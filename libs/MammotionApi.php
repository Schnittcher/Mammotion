<?php

declare(strict_types=1);

//Fehler der Mammotion Open API. Der HTTP-Status und der Code aus dem Antwortumschlag bleiben erhalten,
//damit der Aufrufer zwischen abgelaufenem Token (401) und fachlichen Fehlern unterscheiden kann.
class MammotionApiException extends RuntimeException
{
    public function __construct(string $message, private int $httpStatus = 0, private int $apiCode = 0)
    {
        parent::__construct($message);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getApiCode(): int
    {
        return $this->apiCode;
    }
}

//Schlanker Client fÃ¼r die Mammotion Open API (https://developer.mammotion.com). Kennt weder Symcon noch den Token-Speicher:
//das Modul reicht das Token herein und legt die Antwort von requestToken() selbst ab.
class MammotionApi
{
    public const AUTH_URL = 'https://id.mammotion.com';
    public const API_URL = 'https://api-open.mammotion.com';

    //Die Portal-Beispiele nennen Code 0, die OpenAPI-Spezifikation 200. Beides gilt als Erfolg.
    private const SUCCESS_CODES = [0, 200];

    public function __construct(private string $Language = 'en-US')
    {
    }

    /**
     * Holt ein Token per Client-Credentials oder, wenn ein Refresh-Token Ã¼bergeben wird, per Refresh-Grant.
     *
     * @return array{AccessToken: string, RefreshToken: string, ExpiresAt: int}
     */
    public function requestToken(string $ClientID, string $ClientSecret, ?string $RefreshToken = null): array
    {
        $fields = ['client_id' => $ClientID, 'client_secret' => $ClientSecret];
        if ($RefreshToken !== null && $RefreshToken !== '') {
            $fields['grant_type'] = 'refresh_token';
            $fields['refresh_token'] = $RefreshToken;
        } else {
            $fields['grant_type'] = 'client_credentials';
        }
        [$status, $body] = $this->http('POST', self::AUTH_URL . '/oauth2/token', http_build_query($fields), ['Content-Type: application/x-www-form-urlencoded']);
        $response = json_decode($body, true);
        //Das Portal zeigt die Antwort ohne Umschlag, der Server liefert sie im Feld data des Ã¼blichen Umschlags (code, msg, data):
        //beides annehmen.
        $data = is_array($response) && is_array($response['data'] ?? null) && isset($response['data']['access_token']) ? $response['data'] : $response;
        if ($status !== 200 || !is_array($data) || empty($data['access_token'])) {
            //Wie der Token-Endpunkt einen abgelehnten Grant meldet, ist nicht dokumentiert: jeder Fehlschlag gilt als Ablehnung.
            $message = is_array($response) ? (string) ($response['error_description'] ?? $response['msg'] ?? $response['error'] ?? '') : '';
            if ($status === 200) {
                $message = 'No access token in the response' . ($message !== '' ? ' (' . $message . ')' : '');
            }
            throw new MammotionApiException($message !== '' ? $message : 'Token request failed (HTTP ' . $status . ')', $status === 200 ? 401 : $status);
        }
        return [
            'AccessToken'  => (string) $data['access_token'],
            'RefreshToken' => (string) ($data['refresh_token'] ?? ''),
            'ExpiresAt'    => time() + (int) ($data['expires_in'] ?? 3600),
        ];
    }

    /**
     * Ruft einen Endpunkt von api-open.mammotion.com auf und gibt das Feld `data` des Antwortumschlags zurÃ¼ck.
     */
    public function call(string $Method, string $Path, string $Token, ?array $Body = null): mixed
    {
        $headers = [
            'Authorization: Bearer ' . $Token,
            'Accept: application/json',
            'Accept-Language: ' . $this->Language,
        ];
        $payload = null;
        if ($Body !== null) {
            $headers[] = 'Content-Type: application/json';
            $payload = json_encode($Body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        [$status, $response] = $this->http($Method, self::API_URL . $Path, $payload, $headers);
        $envelope = json_decode($response, true);
        if (!is_array($envelope)) {
            throw new MammotionApiException('Unexpected response (HTTP ' . $status . ')', $status);
        }
        $code = (int) ($envelope['code'] ?? 0);
        if ($status === 401 || $status >= 400 || !in_array($code, self::SUCCESS_CODES, true)) {
            throw new MammotionApiException((string) ($envelope['msg'] ?? 'Request failed (HTTP ' . $status . ')'), $status, $code);
        }
        return $envelope['data'] ?? null;
    }

    /**
     * @return array{0: int, 1: string} HTTP-Status und Antworttext
     */
    protected function http(string $Method, string $Url, ?string $Body, array $Headers): array
    {
        $curl = curl_init($Url);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST  => $Method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => $Headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($Body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $Body);
        }
        $response = curl_exec($curl);
        if ($response === false) {
            throw new MammotionApiException('Connection failed: ' . curl_error($curl));
        }
        return [(int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), (string) $response];
    }
}

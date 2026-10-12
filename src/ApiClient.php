<?php
/**
 * NexTalk Messenger — Resilient API Client for Node and Auth services
 */
require_once __DIR__ . '/Config.php';

class ApiClient {
    /**
     * Call Node API (port 3001)
     */
    public static function callNode(string $method, string $endpoint, ?array $data = null): array {
        $url = NODE_API_URL . $endpoint;
        $headers = "Content-type: application/json\r\n";
        $headers .= "Bypass-Tunnel-Reminder: true\r\n";
        if (isset($_SESSION['jwt_token'])) {
            $token = $_SESSION['jwt_token'];
            $headers .= "Authorization: Bearer $token\r\n";
            $headers .= "X-Auth-Token: Bearer $token\r\n";
            $headers .= "X-Authorization: Bearer $token\r\n";
        }

        $options = [
            'http' => [
                'header'        => $headers,
                'method'        => $method,
                'ignore_errors' => true,
                'timeout'       => 5,
            ]
        ];
        if ($data !== null) {
            $options['http']['content'] = json_encode($data);
        }

        $context = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            return ['ok' => false, 'error' => 'Node offline'];
        }

        $decoded = json_decode($result, true);
        if (!is_array($decoded)) {
            $snippet = trim(strip_tags(substr($result, 0, 300)));
            return ['ok' => false, 'error' => $snippet ?: 'Invalid response from node'];
        }
        return $decoded;
    }

    /**
     * Call Auth API (nextalk-auth)
     */
    public static function callAuth(string $method, string $endpoint, ?array $data = null, bool $useToken = false): array {
        $url = AUTH_API_URL . $endpoint;
        $headers = "Content-type: application/json\r\n";
        if ($useToken && isset($_SESSION['jwt_token'])) {
            $token = $_SESSION['jwt_token'];
            $headers .= "Authorization: Bearer $token\r\n";
            $headers .= "X-Auth-Token: Bearer $token\r\n";
            $headers .= "X-Authorization: Bearer $token\r\n";
        }

        $options = [
            'http' => [
                'header'        => $headers,
                'method'        => $method,
                'ignore_errors' => true,
                'timeout'       => 5,
            ]
        ];
        if ($data !== null) {
            $options['http']['content'] = json_encode($data);
        }

        $context = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            return ['ok' => false, 'error' => 'Auth server offline'];
        }

        $decoded = json_decode($result, true);
        if (!is_array($decoded)) {
            $snippet = trim(strip_tags(substr($result, 0, 300)));
            return ['ok' => false, 'error' => $snippet ?: 'Invalid response'];
        }
        return $decoded;
    }

    /**
     * Call Auth API with file upload (multipart/form-data)
     */
    public static function callAuthUpload(string $endpoint, array $files, bool $useToken = true): array {
        $url = AUTH_API_URL . $endpoint;
        $boundary = '----' . md5(time());
        $body = '';

        if ($useToken && isset($_SESSION['jwt_token'])) {
            $body .= "--$boundary\r\n";
            $body .= "Content-Disposition: form-data; name=\"token\"\r\n\r\n";
            $body .= $_SESSION['jwt_token'] . "\r\n";
        }

        foreach ($files as $name => $file) {
            if (empty($file['tmp_name']) || !file_exists($file['tmp_name'])) continue;
            $body .= "--$boundary\r\n";
            $body .= "Content-Disposition: form-data; name=\"$name\"; filename=\"" . $file['name'] . "\"\r\n";
            $body .= "Content-Type: " . ($file['type'] ?? 'application/octet-stream') . "\r\n\r\n";
            $body .= file_get_contents($file['tmp_name']) . "\r\n";
        }
        $body .= "--$boundary--\r\n";

        $headers = "Content-Type: multipart/form-data; boundary=$boundary\r\n";
        if ($useToken && isset($_SESSION['jwt_token'])) {
            $token = $_SESSION['jwt_token'];
            $headers .= "Authorization: Bearer $token\r\n";
            $headers .= "X-Auth-Token: Bearer $token\r\n";
            $headers .= "X-Authorization: Bearer $token\r\n";
        }

        $options = [
            'http' => [
                'header'        => $headers,
                'method'        => 'POST',
                'content'       => $body,
                'ignore_errors' => true,
                'timeout'       => 10,
            ]
        ];

        $context = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            return ['ok' => false, 'error' => 'Auth server offline'];
        }

        return json_decode($result, true) ?? ['ok' => false, 'error' => 'Invalid response'];
    }
}

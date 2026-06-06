<?php

if (!function_exists('build_html2image_document')) {
    function build_html2image_document(string $html, string $css): string
    {
        return "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <style>{$css}</style>
</head>
<body>{$html}</body>
</html>";
    }
}

if (!function_exists('generate_html2image_link')) {
    function generate_html2image_link(string $html, string $css, int $width, int $height, int $delayMs = 2000): ?string
    {
        $apiKey = $_ENV['HTML2IMAGE_API_KEY'] ?? '';
        if (empty($apiKey) || $apiKey === 'your_html2image_api_key_here') {
            return null;
        }

        $document = build_html2image_document($html, $css);
        $url = 'https://www.html2image.net/api/api.php?key=' . urlencode($apiKey)
            . '&type=png&width=' . $width
            . '&height=' . $height
            . '&delay=' . $delayMs
            . '&transparent=false';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => 'source=' . urlencode($document),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 60,
        ]);

        $response = curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            error_log('HTML2Image cURL error: ' . $curlErr);
            return null;
        }

        $result = json_decode((string) $response, true);
        if (!is_array($result)) {
            error_log('HTML2Image invalid response: ' . substr((string) $response, 0, 200));
            return null;
        }

        if (($result['Status'] ?? '') === 'OK' && !empty($result['Link'])) {
            return $result['Link'];
        }

        error_log('HTML2Image error: ' . ($result['Message'] ?? json_encode($result)));
        return null;
    }
}

if (!function_exists('upload_generated_image_to_cloudinary')) {
    function upload_generated_image_to_cloudinary(?string $imageUrl, string $folder): ?string
    {
        if (!$imageUrl) {
            return null;
        }

        if (!class_exists('\Cloudinary\Api\Upload\UploadApi')) {
            return $imageUrl;
        }

        try {
            $uploadApi = new \Cloudinary\Api\Upload\UploadApi();
            $result = $uploadApi->upload($imageUrl, [
                'folder' => $folder,
                'upload_preset' => $_ENV['CLOUDINARY_UPLOAD_PRESET'] ?? '',
            ]);

            return $result['secure_url'] ?? $imageUrl;
        } catch (Exception $e) {
            error_log('Cloudinary upload for generated image failed: ' . $e->getMessage());
            return $imageUrl;
        }
    }
}

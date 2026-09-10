<?php
// A single user can hold at most this many seats on one trip (a specific
// route + departure time) for one travel date, across all of their
// (non-cancelled) tickets combined - not just within a single booking.
// Without this, someone can dodge the "max 5 per booking" rule just by
// submitting the form multiple times.
const MAX_SEATS_PER_TRIP_PER_DATE = 5;

// How many seats a given user already holds on a trip/date, summed across
// all their tickets. Pass $excludeTicketId when editing an existing ticket
// so that ticket doesn't count against itself.
function user_seats_booked($conn, $uid, $trip_id, $travel_date, $excludeTicketId = 0) {
    $stmt = $conn->prepare('SELECT COALESCE(SUM(seat_quantity), 0) AS booked FROM tickets WHERE user_id = ? AND trip_id = ? AND travel_date = ? AND id != ?');
    $stmt->bind_param('iisi', $uid, $trip_id, $travel_date, $excludeTicketId);
    $stmt->execute();
    $booked = (int)$stmt->get_result()->fetch_assoc()['booked'];
    $stmt->close();
    return $booked;
}

// TAR UMT's faculties and centres, used to populate the Faculty dropdown on
// registration and the account page instead of a free-text field.
function tarumt_faculties() {
    return [
        'Faculty of Accountancy, Finance and Business',
        'Faculty of Applied Sciences',
        'Faculty of Computing and Information Technology',
        'Faculty of Built Environment',
        'Faculty of Engineering and Technology',
        'Faculty of Communication and Creative Industries',
        'Faculty of Social Science and Humanities',
        'Centre for Pre-University Studies',
        'Centre for Postgraduate Studies and Research',
        'Centre for Continuing and Professional Education',
        'Centre for Business Incubation and Entrepreneurial Ventures',
        'SME Centre',
        'Student Career Development Centre',
        'Institute of Social Economic Research (ISER)',
    ];
}

// True if a route's departure time (e.g. "08:00") on the given date has
// already passed relative to now - a route scheduled for today whose bus
// already left can't be booked for today (a future date is never "in the
// past" no matter the departure time).
function is_departure_in_past($date, $departureTime) {
    $depart = strtotime($date . ' ' . $departureTime);
    return $depart !== false && $depart < time();
}

// Falls back to a neutral placeholder until an admin uploads a real photo.
//
// An S3-stored photo's image_url is already a full https:// URL - returned
// as-is. A local-disk photo's image_url is root-relative ("/uploads/xxx.jpg")
// and gets turned into a path relative to the current script instead, because
// this app may be hosted as a subdirectory alongside sibling apps (not at the
// web server's document root) - a leading "/uploads/..." would then resolve
// to the wrong app's uploads folder (or nowhere).
function entity_image_url($row) {
    if (!empty($row['image_url'])) {
        if (str_starts_with($row['image_url'], 'https://') || str_starts_with($row['image_url'], 'http://')) {
            return $row['image_url'];
        }

        $relative = ltrim($row['image_url'], '/');
        $prefix = str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') ? '../' : '';

        $path = __DIR__ . '/' . $relative;
        $version = is_file($path) ? '?v=' . filemtime($path) : '';
        return $prefix . $relative . $version;
    }

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="300">'
         . '<rect width="100%" height="100%" fill="#e1e5eb"/>'
         . '<text x="50%" y="50%" font-size="18" fill="#626a76" text-anchor="middle" dy=".3em">No photo yet</text>'
         . '</svg>';

    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

// Validates an uploaded photo, then stores it either on S3 (if AWS_S3_BUCKET
// is configured, see config.php) or on local disk (the default). Returns
// [webPath, error] - webPath is either a full S3 https:// URL or a
// root-relative "/uploads/xxx.jpg" path, or null if no file was uploaded or
// it failed.
function handle_image_upload($file, $uploadDir, $prefix = 'photo') {
    if (!isset($file) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [null, 'Image upload failed. Please try again.'];
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        return [null, 'Image must be smaller than 5MB.'];
    }

    // Check the actual file content, not just the extension/MIME the browser
    // claims, so a renamed .php file can't slip through.
    $imageInfo = getimagesize($file['tmp_name']);
    if ($imageInfo === false) {
        return [null, 'The uploaded file is not a valid image.'];
    }

    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];
    if (!isset($allowedMimes[$imageInfo['mime']])) {
        return [null, 'Only JPG, PNG, GIF or WEBP images are allowed.'];
    }

    $filename = uniqid($prefix . '_', true) . '.' . $allowedMimes[$imageInfo['mime']];

    // Optimize image size: if GD is available, resize wide images (max 800px)
    // and recompress to significantly reduce payload transfer over the network.
    $fileData = file_get_contents($file['tmp_name']);
    if (function_exists('imagecreatefromstring')) {
        $srcImg = @imagecreatefromstring($fileData);
        if ($srcImg !== false) {
            $origW = imagesx($srcImg);
            $origH = imagesy($srcImg);
            $maxW = 800;
            if ($origW > $maxW) {
                $newW = $maxW;
                $newH = (int)($origH * ($maxW / $origW));
                $dstImg = imagecreatetruecolor($newW, $newH);
                imagealphablending($dstImg, false);
                imagesavealpha($dstImg, true);
                imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
                imagedestroy($srcImg);
                $srcImg = $dstImg;
            }
            ob_start();
            if ($imageInfo['mime'] === 'image/png') {
                imagepng($srcImg, null, 8);
            } else {
                imagejpeg($srcImg, null, 80);
            }
            $fileData = ob_get_clean();
            imagedestroy($srcImg);
        }
    }

    if (AWS_S3_BUCKET !== '') {
        return s3_put_object('uploads/' . $filename, $fileData, $imageInfo['mime']);
    }

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    if (file_put_contents($uploadDir . '/' . $filename, $fileData) === false) {
        return [null, 'Could not save the uploaded image.'];
    }

    return ['/uploads/' . $filename, null];
}

// Deletes a previously uploaded image, from S3 or local disk depending on
// which one image_url points at.
function delete_image_file($imageUrl, $uploadDir) {
    if (!$imageUrl) {
        return;
    }
    if (str_starts_with($imageUrl, 'https://') || str_starts_with($imageUrl, 'http://')) {
        s3_delete_object($imageUrl);
        return;
    }
    if (str_starts_with($imageUrl, '/uploads/')) {
        $path = $uploadDir . '/' . basename($imageUrl);
        if (is_file($path)) {
            unlink($path);
        }
    }
}

// ============================================================================
// S3 upload support (Signature Version 4, no AWS SDK/Composer dependency).
// Only used when AWS_S3_BUCKET is set in config.php - local disk is the
// default and needs none of this. The signing logic here is verified
// byte-for-byte against AWS's own published SigV4 test suite.
// ============================================================================

// Builds the canonical request + the list of header names that were signed,
// per the SigV4 spec: https://docs.aws.amazon.com/general/latest/gr/sigv4-create-canonical-request.html
function s3_canonical_request($method, $path, $headers, $payloadHash) {
    $sorted = $headers;
    ksort($sorted);
    $canonicalHeaders = '';
    foreach ($sorted as $name => $value) {
        $canonicalHeaders .= strtolower($name) . ':' . trim($value) . "\n";
    }
    $signedHeaders = implode(';', array_map('strtolower', array_keys($sorted)));
    $canonicalRequest = implode("\n", [$method, $path, '', $canonicalHeaders, $signedHeaders, $payloadHash]);
    return [$canonicalRequest, $signedHeaders];
}

// Signs an S3 request and returns [host, headers] with the Authorization
// header already filled in.
function s3_sign($method, $bucket, $region, $key, $payload, $credentials) {
    $host = "$bucket.s3.$region.amazonaws.com";
    $amzDate = gmdate('Ymd\THis\Z');
    $dateStamp = gmdate('Ymd');
    $payloadHash = hash('sha256', $payload);

    $headers = [
        'Host' => $host,
        'X-Amz-Date' => $amzDate,
        'X-Amz-Content-Sha256' => $payloadHash,
    ];
    if (!empty($credentials['token'])) {
        $headers['X-Amz-Security-Token'] = $credentials['token'];
    }

    [$canonicalRequest, $signedHeaders] = s3_canonical_request($method, '/' . $key, $headers, $payloadHash);

    $service = 's3';
    $credentialScope = "$dateStamp/$region/$service/aws4_request";
    $stringToSign = implode("\n", [
        'AWS4-HMAC-SHA256',
        $amzDate,
        $credentialScope,
        hash('sha256', $canonicalRequest),
    ]);

    $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $credentials['secret_key'], true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    $signingKey = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $signingKey);

    $headers['Authorization'] = "AWS4-HMAC-SHA256 Credential={$credentials['access_key']}/$credentialScope, "
        . "SignedHeaders=$signedHeaders, Signature=$signature";

    return [$host, $headers];
}

// Gets S3 credentials one of two ways: first by asking the EC2 instance's
// own metadata service (IMDSv2) for whatever IAM role is attached - the
// preferred way, since those credentials are temporary and rotated
// automatically with nothing to leak. If there's no role to ask (e.g.
// running locally, or an AWS Academy Learner Lab where you can't attach
// one), falls back to explicit AWS_ACCESS_KEY_ID/AWS_SECRET_ACCESS_KEY/
// AWS_SESSION_TOKEN from config.php (set as environment variables, e.g.
// copied from a Learner Lab's "AWS Details" panel - never hardcoded/
// committed). Returns null if neither is available, quickly (short
// timeouts on the metadata service calls) so this never hangs a request.
function s3_instance_credentials() {
    $credentials = s3_role_credentials();
    if ($credentials) {
        return $credentials;
    }

    if (AWS_ACCESS_KEY_ID !== '' && AWS_SECRET_ACCESS_KEY !== '') {
        return [
            'access_key' => AWS_ACCESS_KEY_ID,
            'secret_key' => AWS_SECRET_ACCESS_KEY,
            'token' => AWS_SESSION_TOKEN,
        ];
    }

    return null;
}

// The IMDSv2 half of s3_instance_credentials() - split out so the fallback
// logic above stays easy to follow.
function s3_role_credentials() {
    $tokenCtx = stream_context_create(['http' => [
        'method' => 'PUT',
        'header' => "X-aws-ec2-metadata-token-ttl-seconds: 21600\r\n",
        'timeout' => 1,
        'ignore_errors' => true,
    ]]);
    $token = @file_get_contents('http://169.254.169.254/latest/api/token', false, $tokenCtx);
    if ($token === false || $token === '') {
        return null;
    }

    $metaCtx = stream_context_create(['http' => [
        'method' => 'GET',
        'header' => "X-aws-ec2-metadata-token: $token\r\n",
        'timeout' => 1,
        'ignore_errors' => true,
    ]]);
    $roleName = trim((string)@file_get_contents(
        'http://169.254.169.254/latest/meta-data/iam/security-credentials/',
        false,
        $metaCtx
    ));
    if ($roleName === '') {
        return null;
    }

    $credsJson = @file_get_contents(
        "http://169.254.169.254/latest/meta-data/iam/security-credentials/$roleName",
        false,
        $metaCtx
    );
    $creds = $credsJson ? json_decode($credsJson, true) : null;
    if (!isset($creds['AccessKeyId'], $creds['SecretAccessKey'], $creds['Token'])) {
        return null;
    }

    return [
        'access_key' => $creds['AccessKeyId'],
        'secret_key' => $creds['SecretAccessKey'],
        'token' => $creds['Token'],
    ];
}

// Uploads $data to S3 under $key. Returns [publicUrl, error], matching the
// shape handle_image_upload()'s callers already expect.
function s3_put_object($key, $data, $contentType) {
    $credentials = s3_instance_credentials();
    if (!$credentials) {
        return [null, 'Could not get S3 credentials: no IAM role is attached to this instance, and '
            . 'AWS_ACCESS_KEY_ID/AWS_SECRET_ACCESS_KEY are not set either. See config.php.'];
    }

    [$host, $headers] = s3_sign('PUT', AWS_S3_BUCKET, AWS_S3_REGION, $key, $data, $credentials);
    $headers['Content-Type'] = $contentType;
    // Set long-lived immutable cache control so browsers cache static photos locally for 1 year
    $headers['Cache-Control'] = 'public, max-age=31536000, immutable';

    $headerLines = '';
    foreach ($headers as $name => $value) {
        $headerLines .= "$name: $value\r\n";
    }

    $context = stream_context_create(['http' => [
        'method' => 'PUT',
        'header' => $headerLines,
        'content' => $data,
        'timeout' => 20,
        'ignore_errors' => true,
    ]]);

    @file_get_contents("https://$host/$key", false, $context);
    $status = s3_response_status($http_response_header ?? []);

    if ($status !== 200) {
        return [null, "S3 upload failed (HTTP $status)."];
    }

    // Return CloudFront CDN URL if configured, otherwise direct S3 URL
    $cdnDomain = defined('AWS_CDN_DOMAIN') ? trim(AWS_CDN_DOMAIN) : '';
    if ($cdnDomain !== '') {
        return ["https://$cdnDomain/$key", null];
    }

    return ["https://$host/$key", null];
}

// Deletes an object previously uploaded to S3, given the URL stored in
// image_url. Does nothing if the URL doesn't belong to the configured
// bucket or CDN domain (defensive - shouldn't happen in practice).
function s3_delete_object($url) {
    $host = AWS_S3_BUCKET . '.s3.' . AWS_S3_REGION . '.amazonaws.com';
    $cdnDomain = defined('AWS_CDN_DOMAIN') ? trim(AWS_CDN_DOMAIN) : '';

    $prefix = '';
    if (str_starts_with($url, "https://$host/")) {
        $prefix = "https://$host/";
    } elseif ($cdnDomain !== '' && str_starts_with($url, "https://$cdnDomain/")) {
        $prefix = "https://$cdnDomain/";
    } else {
        return;
    }
    $key = substr($url, strlen($prefix));

    $credentials = s3_instance_credentials();
    if (!$credentials) {
        return;
    }

    [, $headers] = s3_sign('DELETE', AWS_S3_BUCKET, AWS_S3_REGION, $key, '', $credentials);
    $headerLines = '';
    foreach ($headers as $name => $value) {
        $headerLines .= "$name: $value\r\n";
    }

    $context = stream_context_create(['http' => [
        'method' => 'DELETE',
        'header' => $headerLines,
        'timeout' => 10,
        'ignore_errors' => true,
    ]]);
    @file_get_contents("https://$host/$key", false, $context);
}

// Pulls the HTTP status code out of the $http_response_header array that
// PHP's stream wrapper populates after a file_get_contents() HTTP request.
function s3_response_status($responseHeaders) {
    foreach ($responseHeaders as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
            return (int)$m[1];
        }
    }
    return 0;
}

// ============================================================================
// Amazon SNS Notification Support (SigV4 signing, zero SDK/Composer dependency)
// Used to broadcast alerts for new bookings, testimonials, and contact inquiries
// to the shared alerts SNS topic (SetEnv SNS_TOPIC_ARN).
// ============================================================================

// Signs an AWS SNS query API request using Signature Version 4
function sns_sign($method, $region, $payload, $credentials) {
    $host = "sns.$region.amazonaws.com";
    $amzDate = gmdate('Ymd\THis\Z');
    $dateStamp = gmdate('Ymd');
    $payloadHash = hash('sha256', $payload);

    $headers = [
        'Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8',
        'Host' => $host,
        'X-Amz-Date' => $amzDate,
    ];
    if (!empty($credentials['token'])) {
        $headers['X-Amz-Security-Token'] = $credentials['token'];
    }

    $sorted = $headers;
    ksort($sorted);
    $canonicalHeaders = '';
    foreach ($sorted as $name => $value) {
        $canonicalHeaders .= strtolower($name) . ':' . trim($value) . "\n";
    }
    $signedHeaders = implode(';', array_map('strtolower', array_keys($sorted)));
    $canonicalRequest = implode("\n", [$method, '/', '', $canonicalHeaders, $signedHeaders, $payloadHash]);

    $service = 'sns';
    $credentialScope = "$dateStamp/$region/$service/aws4_request";
    $stringToSign = implode("\n", [
        'AWS4-HMAC-SHA256',
        $amzDate,
        $credentialScope,
        hash('sha256', $canonicalRequest),
    ]);

    $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $credentials['secret_key'], true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    $signingKey = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $signingKey);

    $headers['Authorization'] = "AWS4-HMAC-SHA256 Credential={$credentials['access_key']}/$credentialScope, "
        . "SignedHeaders=$signedHeaders, Signature=$signature";

    return [$host, $headers];
}

// Publishes a message to an Amazon SNS topic. Returns [true, null] on success or
// [false, error_message] on failure. Non-blocking with short timeout (5s).
function sns_publish($subject, $message, $topicArn = null) {
    $topicArn = $topicArn ?: (defined('AWS_SNS_TOPIC_ARN') ? trim(AWS_SNS_TOPIC_ARN) : '');
    if ($topicArn === '') {
        return [false, 'No SNS topic ARN configured'];
    }

    $region = 'us-east-1';
    if (preg_match('/^arn:aws:sns:([^:]+):/', $topicArn, $m)) {
        $region = $m[1];
    } elseif (defined('AWS_S3_REGION') && AWS_S3_REGION !== '') {
        $region = AWS_S3_REGION;
    }

    $credentials = s3_instance_credentials();
    if (!$credentials) {
        return [false, 'No AWS credentials available for SNS publish'];
    }

    $params = [
        'Action' => 'Publish',
        'Version' => '2010-03-31',
        'TopicArn' => $topicArn,
        'Subject' => substr($subject, 0, 100),
        'Message' => $message,
    ];
    $payload = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

    [$host, $headers] = sns_sign('POST', $region, $payload, $credentials);

    $headerLines = '';
    foreach ($headers as $name => $value) {
        $headerLines .= "$name: $value\r\n";
    }

    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => $headerLines,
        'content' => $payload,
        'timeout' => 5,
        'ignore_errors' => true,
    ]]);

    @file_get_contents("https://$host/", false, $context);
    $status = s3_response_status($http_response_header ?? []);

    if ($status >= 200 && $status < 300) {
        return [true, null];
    }

    return [false, "SNS publish failed with HTTP status $status"];
}


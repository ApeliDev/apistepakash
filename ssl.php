<?php
// check_ssl.php
echo "OpenSSL Extension: " . (extension_loaded('openssl') ? 'Enabled' : 'Disabled') . "\n";
echo "OpenSSL Version: " . OPENSSL_VERSION_TEXT . "\n";

// Check available stream transports
$transports = stream_get_transports();
echo "Available transports: " . implode(', ', $transports) . "\n";

// Check if SSL/TLS is supported
echo "SSL support: " . (in_array('ssl', $transports) ? 'Yes' : 'No') . "\n";
echo "TLS support: " . (in_array('tls', $transports) ? 'Yes' : 'No') . "\n";

// Check stream wrappers
$wrappers = stream_get_wrappers();
echo "Available wrappers: " . implode(', ', $wrappers) . "\n";
?>
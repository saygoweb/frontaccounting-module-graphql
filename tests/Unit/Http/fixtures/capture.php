<?php

/*
    Served by OutputCaptureServerTest through PHP's built-in web server: stands in
    for index.php with FrontAccounting misbehaving in the way ?case= names. Test
    code only; never deployed (tests/ is denied by .htaccess).
*/

use FA\GraphQL\Http\OutputCapture;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

// As a legacy config.php would: errors printed into the body. The capture must
// still keep them from the client.
ini_set('display_errors', '1');
ini_set('error_log', (string) getenv('CAPTURE_LOG'));

OutputCapture::start();

echo '<html>FrontAccounting says hello</html>';

switch ($_GET['case'] ?? '') {
    case 'exit':
        exit;
    case 'die':
        die('Restricted access');
    case 'fatal':
        undefined_function_for_the_capture_test();
        break;
    case 'user-error':
        trigger_error('FrontAccounting gave up', E_USER_ERROR);
        break;
    case 'header-then-exit':
        header('HTTP/1.1 401 Authorization Required');
        header('X-FrontAccounting: 1');
        exit;
    case 'normal-header':
        header('X-FrontAccounting: 1');
        break;
    case 'handler':
        ob_start(static function (string $buffer): string {
            header('X-FrontAccounting: 1');

            return '<b>' . $buffer . '</b>';
        });
        echo 'inside a handler';
        break;
    case 'late':
        register_shutdown_function(static function (): void {
            echo 'printed by a later shutdown function';
        });
        break;
    case 'late-then-exit':
        register_shutdown_function(static function (): void {
            echo 'LATE';
        });
        exit;
}

OutputCapture::end();
header('Content-Type: application/json; charset=utf-8');
echo '{"data":{"ok":true}}';
OutputCapture::afterEmit();

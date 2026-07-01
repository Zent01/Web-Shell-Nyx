## 📋 Overview

**Shell Nyx** is a sophisticated web-based shell interface built in PHP, designed for penetration testers, security researchers, and system administrators who need stealthy remote access to target environments. It combines a fully functional terminal emulator with an advanced file manager, providing comprehensive system control through a sleek, modern interface.

### 🎯 Why Penetration Testers Choose Shell Nyx

| Feature | Benefit for Pentesters |
|---------|----------------------|
| 🕵️ **Stealth Mode** | Uses standard PHP requests, blending with normal web traffic |
| 🌐 **Universal Compatibility** | Works on any server with PHP 7.0+ - no special requirements |
| 📁 **Complete File Access** | Full file system manipulation without command-line limitations |
| 🔄 **Persistence Support** | Session-based caching maintains state across requests |
| 🚀 **Lightweight** | Single file deployment - no installation, no traces |
| 🖥️ **Cross-Platform** | Works identically on Linux, Windows, and macOS targets |
| 🛡️ **Anti-Forensics** | No logs created by default, cache auto-expires |

### Common Pentesting Use Cases

- **Post-Exploitation Management** - Maintain access and control compromised systems
- **File Exfiltration** - Download sensitive files through the built-in download manager
- **Privilege Escalation** - Execute commands to identify and exploit privilege vectors
- **Lateral Movement** - Use the terminal to pivot to other systems in the network
- **Data Collection** - Search, filter, and extract system information efficiently
- **Web Shell Replacement** - Superior alternative to basic `cmd.php` or `shell.php` scripts

---

## ✨ Key Features

### 💻 **Full Terminal Emulation**
- Complete command-line interface with all standard system commands
- Persistent command history with up/down arrow navigation
- Intelligent tab completion for commands and file paths
- Built-in `cd`, `pwd`, and `clear` commands with OS-specific path handling
- Customizable prompt showing current working directory

### 📁 **Advanced File Manager**
- **Intuitive Navigation** - Click to browse directories, path bar for direct access
- **Drag & Drop Upload** - Upload multiple files simultaneously
- **Inline File Editing** - Edit text files directly in the browser
- **Batch Operations** - Download, rename, and delete files with confirmation
- **Smart Search** - Real-time filtering with highlighted matches
- **Pagination** - Handles directories with thousands of files efficiently
- **Virtual Scrolling** - Optimized rendering for large directories

### 🌐 **Cross-Platform Support**
- **Windows** - Full PowerShell support, drive letter detection, backslash path display
- **Linux** - Native command execution, POSIX user detection
- **macOS** - Darwin kernel detection, Unix-compatible commands
- **OS-Specific Optimizations** - Adjusts prompt symbol, path separators, and commands

### 📊 **Real-Time System Intelligence**
- **OS Detection** - Auto-detects Windows, Linux, or macOS with visual badges
- **User Information** - Displays current username with multiple fallback detection methods
- **Network Discovery** - Shows hostname and all non-local IP addresses
- **System Monitoring** - Kernel version, architecture, and system uptime
- **Live Updates** - System info refreshes automatically every 30 seconds

### 🎨 **Modern User Interface**
- **Dark Theme** - Reduces eye strain during extended sessions
- **Responsive Design** - Works on desktop, tablet, and mobile devices
- **Keyboard Shortcuts** - Quick navigation with Ctrl+1, Ctrl+2, Ctrl+3
- **Customizable Colors** - CSS variables for easy theming
- **Tooltip Information** - Hover for detailed system information
- **Interactive Elements** - Smooth animations and visual feedback

### ⚡ **Performance Optimizations**
- **Session Caching** - Directory listings cached for 10 seconds
- **Pagination** - Load 100 files at a time for large directories
- **Virtual Scrolling** - Only renders visible rows in file manager
- **Debounced Events** - Search and resize events optimized for performance
- **Base64 Encoding** - Safe data transmission for file contents
- **Selective Rendering** - Lazy loading of file manager components

### 🔒 **Security & Stealth**
- **Input Sanitization** - Protects against injection attacks
- **Path Traversal Prevention** - Uses `realpath()` and `basename()` for validation
- **Session-Based Caching** - No sensitive data written to disk
- **Minimal Footprint** - Single file deployment, no database required
- **Customizable Access** - Can be integrated with existing authentication systems

### 🛠️ **Advanced Capabilities**
- **Command Execution** - Support for all system commands through multiple PHP execution methods
- **File Upload** - Multi-file upload with progress tracking
- **File Download** - Direct file downloads with proper MIME types
- **File Operations** - Create, delete, rename, edit, and search files
- **Directory Management** - Create, delete, and navigate directories
- **Process Management** - View and manage system processes
- **Script Execution** - Run Python, Perl, Ruby, Bash, and PowerShell scripts

---

## 🚀 Installation

### Quick Deployment for Pentesters

1. **Download** the `Shell Nyx.php` file
2. **Rename** to something innocuous (e.g., `style.css.php`, `image.php`, `404.php`) to avoid detection
3. **Upload** to the target web server via an existing vulnerability or compromised credentials
4. **Access** via browser: `https://target-server.com/random-name.php`
5. **Clean Up** - Remove or obfuscate the file after use to avoid leaving traces


## ⚠️ Legal & Ethical Disclaimer

> **WARNING:** Shell Nyx is designed for **authorized penetration testing** and **security research only**. Unauthorized access to computer systems is illegal in most jurisdictions. Users are solely responsible for obtaining proper authorization before using this tool. The developer assumes no liability for misuse or damages caused by this software.

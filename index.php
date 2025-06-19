<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0); // Set to 1 for debugging, 0 for production
set_time_limit(300); // 5 minutes execution time, adjust as needed
ini_set('memory_limit', '256M'); // Adjust as needed

define('MAX_URLS_PER_SITEMAP', 10000);
define('SITEMAP_DIR', __DIR__ . '/generated_sitemaps/');
define('SITEMAP_URL_PREFIX', './generated_sitemaps/'); // Relative URL for download links

if (!is_dir(SITEMAP_DIR) && !mkdir(SITEMAP_DIR, 0755, true)) {
    die('Failed to create sitemap directory: ' . SITEMAP_DIR);
}
if (!is_writable(SITEMAP_DIR)) {
    die('Sitemap directory is not writable: ' . SITEMAP_DIR);
}

// --- Helper Functions ---

function fetchUrlContent($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'GushiSitemapGenerator/1.0 (+http://www.gushiio.com/sitemap-tool)');
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $data = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http_code >= 200 && $http_code < 300) {
        return $data;
    }
    return false;
}

/**
 * Resolves a relative URL to an absolute URL based on a base URL.
 * Handles various relative link types including directory-style links.
 *
 * @param string $relativeUrl The relative URL to resolve.
 * @param string $baseUrl The base URL from which the relative URL originates.
 * @return string The absolute URL.
 */
function resolveUrl($relativeUrl, $baseUrl) {
    // If already an absolute URL (starts with a scheme or //)
    if (parse_url($relativeUrl, PHP_URL_SCHEME) != '' || substr($relativeUrl, 0, 2) === '//') {
        if (substr($relativeUrl, 0, 2) === '//') {
            $baseScheme = parse_url($baseUrl, PHP_URL_SCHEME);
            return ($baseScheme ? $baseScheme : 'http') . ':' . $relativeUrl;
        }
        return $relativeUrl;
    }

    $base = parse_url($baseUrl);
    if (empty($base['scheme']) || empty($base['host'])) {
        return $relativeUrl; // Cannot resolve without a proper base scheme and host
    }

    $currentBasePath = '';
    if (isset($base['path'])) {
        // If the base URL path ends with a slash, it's a directory
        if (substr($base['path'], -1) === '/') {
            $currentBasePath = $base['path'];
        } else {
            // Otherwise, it's likely a file, so get its directory
            $currentBasePath = dirname($base['path']);
            // dirname might return '.' for root files or just a segment without leading slash
            // ensure it ends with a slash for proper concatenation
            if ($currentBasePath === '.' || $currentBasePath === DIRECTORY_SEPARATOR) { // DIRECTORY_SEPARATOR handles OS differences
                $currentBasePath = '/';
            } elseif (substr($currentBasePath, -1) !== '/') {
                 $currentBasePath .= '/';
            }
        }
    } else {
        // No path in base URL means it's just the domain, so root path
        $currentBasePath = '/';
    }


    // If relative URL starts with '/', it's relative to the domain root
    if (substr($relativeUrl, 0, 1) === '/') {
        $absolutePath = $relativeUrl;
    } else {
        // Relative path, combine with current base path
        // Remove leading './' from relativeUrl if present
        $relativeUrl = preg_replace('/^\.\//', '', $relativeUrl);
        $absolutePath = $currentBasePath . $relativeUrl;
    }

    // Normalize the path (resolve ../ and ./)
    $parts = [];
    // Ensure absolutePath starts with a slash for explode to work consistently with root
    if (substr($absolutePath, 0, 1) !== '/') {
        $absolutePath = '/' . $absolutePath;
    }

    foreach (explode('/', $absolutePath) as $part) {
        if ($part === '' || $part === '.') {
            // For the first part after explode, an empty string means root, keep it if parts is empty
            if ($part === '' && empty($parts)) {
                 // This case handles the leading slash correctly
            } else if ($part === '.' || ($part === '' && !empty($parts))) { // Ignore . or empty segments (multiple slashes)
                continue;
            }
        } else if ($part === '..') {
            if (!empty($parts)) { // Only pop if there's something to pop
                 array_pop($parts);
            }
        } else {
            $parts[] = $part;
        }
    }
    $normalizedPath = '/' . implode('/', $parts); 


    $port = isset($base['port']) ? ':' . $base['port'] : '';
    $resolvedUrl = $base['scheme'] . '://' . $base['host'] . $port . $normalizedPath;
    
    return $resolvedUrl;
}


function extractLinks($html, $baseUrl, $keywordsFilterArray, $targetDomain) {
    $links = [];
    if (empty($html)) return $links;

    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $anchors = $dom->getElementsByTagName('a');

    $keywordsFilterArray = array_filter(array_map('trim', $keywordsFilterArray));

    foreach ($anchors as $anchor) {
        if ($anchor->hasAttribute('href')) {
            $href = trim($anchor->getAttribute('href')); 
            if (empty($href) || preg_match('/^(javascript:|mailto:|tel:|#|data:)/i', $href)) {
                continue;
            }

            $absoluteUrl = resolveUrl($href, $baseUrl);
            $urlParts = parse_url($absoluteUrl);

            if (isset($urlParts['host']) && strpos($urlParts['host'], $targetDomain) !== false) {
                if (isset($urlParts['fragment'])) {
                    $absoluteUrl = str_replace('#' . $urlParts['fragment'], '', $absoluteUrl);
                }
                
                $passesFilter = true; 
                if (!empty($keywordsFilterArray)) {
                    $passesFilter = false; 
                    foreach ($keywordsFilterArray as $keyword) {
                        if (strpos($absoluteUrl, $keyword) !== false) {
                            $passesFilter = true;
                            break; 
                        }
                    }
                }

                if ($passesFilter) {
                    $links[] = htmlspecialchars($absoluteUrl, ENT_QUOTES, 'UTF-8');
                }
            }
        }
    }
    return array_unique($links);
}

function updateProgress($processed, $total, $found, $message = '') {
    $_SESSION['sitemap_progress'] = [
        'processed' => $processed,
        'total' => $total,
        'found' => $found,
        'message' => $message,
        'percentage' => $total > 0 ? round(($processed / $total) * 100) : 0
    ];
    session_write_close();
    session_start();
}

if (isset($_GET['action']) && $_GET['action'] == 'get_progress') {
    header('Content-Type: application/json');
    echo json_encode(isset($_SESSION['sitemap_progress']) ? $_SESSION['sitemap_progress'] : ['percentage' => 0, 'message' => 'Initializing...']);
    exit;
}

$sitemapFilesGenerated = [];
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['urls'])) {
    $inputUrls = trim($_POST['urls']);
    $keywordsInput = isset($_POST['keywords']) ? trim($_POST['keywords']) : '';
    
    $initialUrls = preg_split('/\s+/', $inputUrls);
    $initialUrls = array_filter(array_map('trim', $initialUrls));

    $keywordsFilterArray = [];
    if (!empty($keywordsInput)) {
        $keywordsFilterArray = preg_split('/\r\n|\r|\n/', $keywordsInput);
        $keywordsFilterArray = array_filter(array_map('trim', $keywordsFilterArray));
    }


    if (empty($initialUrls)) {
        $errorMessage = "Please enter at least one URL.";
    } else {
        $allExtractedUrls = [];
        $totalUrlsToProcess = count($initialUrls);
        $urlsProcessedCount = 0;
        // $targetDomain = 'data.gushiio.com'; // Keep this if you always target data.gushiio.com
        // For more flexibility, derive from the first valid input URL:
        $firstValidUrlHost = null;
        foreach($initialUrls as $tempUrl) {
            if(filter_var($tempUrl, FILTER_VALIDATE_URL)) {
                $firstValidUrlHost = parse_url($tempUrl, PHP_URL_HOST);
                if ($firstValidUrlHost) break;
            }
        }
        $targetDomain = $firstValidUrlHost ? $firstValidUrlHost : 'data.gushiio.com'; // Fallback


        updateProgress(0, $totalUrlsToProcess, 0, "Starting to process URLs...");

        foreach ($initialUrls as $url) {
            if (filter_var($url, FILTER_VALIDATE_URL) === FALSE) {
                updateProgress($urlsProcessedCount, $totalUrlsToProcess, count($allExtractedUrls), "Skipping invalid URL: $url");
                continue;
            }
            
            $currentUrlParts = parse_url($url);
            // Ensure the current URL being processed is from the target domain.
            if (!isset($currentUrlParts['host']) || strpos($currentUrlParts['host'], $targetDomain) === false) {
                 updateProgress($urlsProcessedCount, $totalUrlsToProcess, count($allExtractedUrls), "Skipping URL not from target domain ($targetDomain): $url");
                 $urlsProcessedCount++; // Still count as "processed" for progress
                 continue;
            }

            updateProgress($urlsProcessedCount, $totalUrlsToProcess, count($allExtractedUrls), "Fetching: $url");
            $htmlContent = fetchUrlContent($url);

            if ($htmlContent) {
                // Pass $targetDomain to extractLinks to ensure extracted links are also from the same domain
                $links = extractLinks($htmlContent, $url, $keywordsFilterArray, $targetDomain);
                foreach ($links as $link) {
                    if (!in_array($link, $allExtractedUrls)) {
                        $allExtractedUrls[] = $link;
                    }
                }
                updateProgress($urlsProcessedCount + 1, $totalUrlsToProcess, count($allExtractedUrls), "Processed: $url - Found " . count($links) . " relevant links.");
            } else {
                updateProgress($urlsProcessedCount + 1, $totalUrlsToProcess, count($allExtractedUrls), "Failed to fetch: $url");
            }
            $urlsProcessedCount++;
        }

        if (!empty($allExtractedUrls)) {
            $numSitemaps = ceil(count($allExtractedUrls) / MAX_URLS_PER_SITEMAP);
            $sitemapBaseName = 'sitemap_' . time();
            $today = date('Y-m-d');

            $tempSitemapFiles = []; 

            for ($i = 0; $i < $numSitemaps; $i++) {
                $sitemapChunk = array_slice($allExtractedUrls, $i * MAX_URLS_PER_SITEMAP, MAX_URLS_PER_SITEMAP);
                $sitemapFileName = $sitemapBaseName . ($numSitemaps > 1 ? ($i + 1) : '') . '.xml';
                $sitemapPath = SITEMAP_DIR . $sitemapFileName;

                $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>');

                foreach ($sitemapChunk as $finalUrl) {
                    $urlNode = $xml->addChild('url');
                    $urlNode->addChild('loc', $finalUrl);
                    $urlNode->addChild('lastmod', $today);
                }
                
                if ($xml->asXML($sitemapPath)) {
                    $sitemapFilesGenerated[] = SITEMAP_URL_PREFIX . $sitemapFileName;
                    if ($numSitemaps > 1) { 
                        $tempSitemapFiles[] = SITEMAP_URL_PREFIX . $sitemapFileName;
                    }
                } else {
                    $errorMessage .= "Failed to write sitemap: $sitemapPath. ";
                }
            }

            if ($numSitemaps > 1) {
                $sitemapIndexFileName = $sitemapBaseName . '_index.xml';
                $sitemapIndexPath = SITEMAP_DIR . $sitemapIndexFileName;
                $indexXml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></sitemapindex>');

                foreach ($tempSitemapFiles as $sitemapFileUrl) { 
                    $fullSitemapUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . dirname($_SERVER['PHP_SELF']) . '/' . $sitemapFileUrl;
                    $fullSitemapUrl = filter_var(str_replace('./', '', $fullSitemapUrl), FILTER_SANITIZE_URL);

                    $sitemapNode = $indexXml->addChild('sitemap');
                    $sitemapNode->addChild('loc', $fullSitemapUrl);
                    $sitemapNode->addChild('lastmod', $today);
                }
                if ($indexXml->asXML($sitemapIndexPath)) {
                    array_unshift($sitemapFilesGenerated, SITEMAP_URL_PREFIX . $sitemapIndexFileName);
                } else {
                     $errorMessage .= "Failed to write sitemap index: $sitemapIndexPath. ";
                }
            }
        } else if (empty($errorMessage)) { // Only show this if no other error occurred
            if ($totalUrlsToProcess > 0 && $urlsProcessedCount == $totalUrlsToProcess) { // And all initial URLs were processed
                 $errorMessage = "No URLs matching the criteria were found or could be extracted from the provided pages.";
            } else if ($totalUrlsToProcess == 0) {
                // This case should be caught by "Please enter at least one URL." earlier
            }
        }
        updateProgress($totalUrlsToProcess, $totalUrlsToProcess, count($allExtractedUrls), "Generation complete. Found " . count($allExtractedUrls) . " total unique URLs.");
    }
    unset($_SESSION['sitemap_progress']);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="keywords" content="鼓狮sitemap生成器, sitemap, xml sitemap, 搜索引擎优化, SEO, 鼓狮知识库, 在线工具">
    <meta name="description" content="鼓狮Sitemap生成器 - 为您的网站生成符合搜索引擎规范的XML Sitemap文件。支持URL过滤和自动分割。">
    <title>鼓狮Sitemap生成器 - 鼓狮知识库</title>
    <link rel="shortcut icon" href="https://tools.gushiio.com/favicon.png" type="image/x-icon">

    <style>
        /* --- 通用样式 & 来自 style.css --- */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol";
        }

        body {
            background-color: #f8f9fa;
            color: #333;
            transition: background-color 0.3s ease, color 0.3s ease;
            display: flex; 
            flex-direction: column; 
            min-height: 100vh; 
            align-items: auto; 
            justify-content: auto; 
            margin: 0;
        }

        body.dark-mode {
            background-color: #1a1a1a;
            color: #e0e0e0;
        }

        /* --- Header 样式 --- */
        header {
            background-color: #222;
            padding: 10px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }

        header .logo {
            display: flex;
            align-items: center;
        }

        header .logo img {
            max-height: 40px;
            display: block;
        }


        .nav-links {
            display: flex;
            gap: 20px;
            align-items: center;
            flex-wrap: wrap;
            justify-content: center;
        }

        .nav-links a {
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            padding: 8px 15px;
            border-radius: 5px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            font-weight: 500;
        }

        .nav-links a:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        .nav-links a::after {
            content: "";
            position: absolute;
            left: 0;
            bottom: 0;
            width: 0;
            height: 3px;
            background-color: #a62a1a;
            transition: width 0.3s ease-out;
        }

        .nav-links a:hover::after {
            width: 100%;
        }

        .dropdown {
            position: relative;
            display: inline-block;
        }

        .dropdown-toggle {
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            padding: 8px 15px;
            border-radius: 5px;
            transition: all 0.3s ease;
            cursor: pointer;
            font-weight: 500;
            display: flex; 
            align-items: center;
        }

        .dropdown-toggle:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: white;
        }

        .dropdown-toggle svg.caret-icon {
            width: 0.8em; 
            height: 0.8em; 
            margin-left: 5px; 
            fill: currentColor; 
             vertical-align: middle; 
             transition: fill 0.3s ease; 
        }


        .dropdown-menu {
            display: none;
            position: absolute;
            background-color: #fff;
            min-width: 180px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
            border-radius: 8px;
            overflow: hidden;
            z-index: 1000;
            top: calc(100% + 10px);
            left: 0;
            opacity: 0;
            transform: translateY(10px);
            transition: all 0.3s ease;
        }

        .dropdown-menu.active {
            display: block;
            opacity: 1;
            transform: translateY(0);
        }

        .dropdown-menu a {
            color: #333;
            padding: 12px 18px;
            text-decoration: none;
            display: block;
            transition: all 0.2s ease;
            font-size: 14px;
            font-weight: normal;
            border-bottom: 1px solid #eee;
        }

        .dropdown-menu a:last-child {
            border-bottom: none;
        }


        .dropdown-menu a:hover {
            background-color: #f0f0f0;
            color: #a62a1a;
        }

        body.dark-mode .dropdown-menu {
            background-color: #333;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.4);
        }

        body.dark-mode .dropdown-menu a {
            color: #e0e0e0;
            border-bottom-color: #444;
        }

        body.dark-mode .dropdown-menu a:hover {
            background-color: #444;
            color: #a62a1a;
        }

        /* --- Banner 样式 --- */
        .banner {
            background-image: url(https://tools.gushiio.com/img/toolsbanner.png);
            background-color: #a62a1a;
            color: white;
            text-align: center;
            padding: 50px 20px;
            margin-bottom: 30px;
            font-size: 28px;
            font-weight: bold;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
            background-size: cover;
            background-position: center;
        }

        body.dark-mode .banner {
            background-color: #8b2214;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
        }

        .banner a {
            color: rgba(255, 255, 255, 0.9);
            text-decoration: underline;
            font-size: 18px;
            font-weight: normal;
        }

        .banner a:hover {
            color: white;
        }
        
        /* --- Container 样式 --- */
        .container {

            padding: 0 20px;
            flex-grow: 1; 
        }

        /* --- Header 按钮样式 --- */
        .btn {
            padding: 8px 15px;
            font-size: 14px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s ease, transform 0.2s ease;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .btn svg.icon {
            width: 1.1em;
            height: 1.1em;
            margin-right: 5px;
            fill: currentColor;
            vertical-align: -0.125em;
            transition: fill 0.3s ease;
        }
        .btn-dark {
            background-color: #555;
            color: #fff;
        }
        .btn-dark:hover {
            background-color: #666;
            transform: scale(1.05);
        }
        body.dark-mode .btn-dark {
            background-color: #666;
        }
        body.dark-mode .btn-dark:hover {
            background-color: #777;
        }

        /* --- Footer 样式 --- */
        .footer {
            text-align: center;
            margin-top: auto; 
            margin-bottom: 0; 
            padding: 20px 20px; 
            background-color: #992718;
            color: white; 
            font-size: 13px;
            width: 100%; 
        }
        .footer a {
            text-decoration: none;
            color: white; 
        }
        body.dark-mode .footer {
            background-color: #7a2013; 
        }

        /* --- Sitemap Generator Specific Styles --- */
        .sitemap-generator-tool {
            background-color: #fff;
            padding: 30px 40px;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            margin: 20px auto 40px auto; 
            max-width: 1000px; 
        }
        body.dark-mode .sitemap-generator-tool {
            background-color: #2c2c2c;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }

        .sitemap-generator-tool h1 {
            color: #a62a1a;
            text-align: center;
            margin-bottom: 30px;
            font-size: 2em;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        body.dark-mode .sitemap-generator-tool h1 {
            color: #e74c3c;
        }
        .sitemap-generator-tool h1 svg {
            width: 1.2em;
            height: 1.2em;
            margin-right: 10px;
            fill: currentColor;
        }

        .form-group {
            margin-bottom: 25px;
        }
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #555;
            font-size: 0.95em;
        }
        body.dark-mode .form-group label {
            color: #bbb;
        }
        .form-group textarea { 
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 1em;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
            background-color: #fdfdfd;
            min-height: 100px; 
            resize: vertical;
        }
        body.dark-mode .form-group textarea {
            background-color: #3a3a3a;
            border-color: #555;
            color: #eee;
        }
        .form-group textarea:focus {
            border-color: #a62a1a;
            box-shadow: 0 0 0 3px rgba(166, 42, 26, 0.2);
            outline: none;
        }
        body.dark-mode .form-group textarea:focus {
            border-color: #e74c3c;
            box-shadow: 0 0 0 3px rgba(231, 76, 60, 0.2);
        }
        .form-group textarea#urls {
            min-height: 120px;
        }
        .form-group textarea#keywords {
            min-height: 80px;
        }

        .form-group small {
            display: block;
            margin-top: 5px;
            font-size: 0.85em;
            color: #777;
        }
        body.dark-mode .form-group small {
            color: #999;
        }

        .submit-btn {
            background-color: #a62a1a;
            color: white;
            padding: 12px 25px;
            font-size: 1.1em;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            transition: background-color 0.3s ease, transform 0.2s ease;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
        }
        .submit-btn:hover {
            background-color: #8c2215;
            transform: translateY(-2px);
        }
        .submit-btn:disabled {
            background-color: #ccc;
            cursor: not-allowed;
        }
        body.dark-mode .submit-btn:disabled {
            background-color: #555;
            color: #888;
        }
        .submit-btn svg {
            width: 1.2em;
            height: 1.2em;
            margin-right: 8px;
            fill: currentColor;
        }

        .progress-bar-container {
            width: 100%;
            background-color: #e0e0e0;
            border-radius: 25px;
            margin-top: 20px;
            overflow: hidden;
            display: none; 
        }
        body.dark-mode .progress-bar-container {
            background-color: #444;
        }
        .progress-bar {
            width: 0%;
            height: 20px;
            background-color: #a62a1a;
            text-align: center;
            line-height: 20px;
            color: white;
            font-size: 0.9em;
            border-radius: 25px;
            transition: width 0.3s ease-in-out;
        }
        body.dark-mode .progress-bar {
            background-color: #e74c3c;
        }
        #progress-message {
            text-align: center;
            margin-top: 8px;
            font-size: 0.9em;
            color: #555;
            display: none; 
        }
        body.dark-mode #progress-message {
            color: #bbb;
        }


        #results {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        body.dark-mode #results {
            border-top-color: #444;
        }
        #results h3 {
            margin-bottom: 15px;
            color: #333;
        }
        body.dark-mode #results h3 {
            color: #ddd;
        }
        #results ul {
            list-style: none;
            padding-left: 0;
        }
        #results li {
            margin-bottom: 10px;
        }
        #results a {
            color: #a62a1a;
            text-decoration: none;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            padding: 8px 12px;
            border-radius: 6px;
            background-color: rgba(166, 42, 26, 0.05);
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        #results a:hover {
            background-color: rgba(166, 42, 26, 0.1);
            color: #8c2215;
        }
        body.dark-mode #results a {
            color: #e74c3c;
            background-color: rgba(231, 76, 60, 0.1);
        }
        body.dark-mode #results a:hover {
            background-color: rgba(231, 76, 60, 0.2);
            color: #d63031;
        }
        #results a svg {
            width: 1em;
            height: 1em;
            margin-right: 8px;
            fill: currentColor;
        }
        .error-message {
            background-color: #ffebee;
            color: #c62828;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #ef9a9a;
        }
        body.dark-mode .error-message {
            background-color: #4a2326;
            color: #ff8a80;
            border-color: #e57373;
        }

        /* --- 响应式调整 --- */
        @media (max-width: 1024px) { 
            .sitemap-generator-tool {
                 max-width: 95%; 
            }
        }

        @media (max-width: 768px) {
            header { flex-direction: column; gap: 15px; padding: 10px 15px; }
            .nav-links { gap: 12px; justify-content: center; width: 100%; }
            .nav-links a, .dropdown-toggle, .btn-dark { padding: 8px 10px; font-size: 13px; }
            .dropdown { width: 100%; text-align: center; }
            .dropdown-toggle { width: 100%; }
            .dropdown-menu { min-width: 100%; left: 0; top: calc(100% + 5px); transform: translateY(5px); }
            .dropdown-menu.active { transform: translateY(0); }
            .dropdown-menu a { padding: 10px 15px; }
            .banner { padding: 40px 15px; font-size: 24px; margin-bottom: 20px; }
            .container { padding: 0 15px; }
            .sitemap-generator-tool { padding: 20px; }
            .sitemap-generator-tool h1 { font-size: 1.8em; }
            .footer { padding: 15px; }
        }

        @media (max-width: 480px) {
            header { padding: 10px; gap: 10px; }
            header .logo img { max-height: 35px; }
            .nav-links { flex-direction: column; gap: 8px; width: 100%; align-items: stretch; }
            .nav-links a, .dropdown-toggle, .btn-dark { text-align: center; padding: 10px; }
            .banner { font-size: 20px; padding: 30px 10px; }
            .container { padding: 0 10px; }
            .sitemap-generator-tool { padding: 15px; }
            .sitemap-generator-tool h1 { font-size: 1.5em; }
            .footer { padding: 10px; }
        }

        /* --- 平滑过渡效果 --- */
        body, header, .nav-links a, .banner, .container, .footer, 
        .dropdown-menu, .btn svg.icon, .dropdown-toggle svg.caret-icon,
        .sitemap-generator-tool, .form-group textarea, .submit-btn,
        .progress-bar, #results a {
            transition: all 0.3s ease;
        }
    </style>
</head>

<body>
    <header>
        <div class="logo">
            <img src="https://tools.gushiio.com/img/gushizhishiku.png" alt="鼓狮知识库logo">
        </div>
        <div class="nav-links">
            <a href="https://www.gushiio.com/">鼓狮首页</a>
            <a href="https://tools.gushiio.com/">工具箱首页</a>
            <a href="https://tools.gushiio.com/proto">在线原型设计</a>
            <a href="https://tools.gushiio.com/jizhang">在线记账</a>
            <a href="https://data.gushiio.com/" target="_blank">鼓狮大数据</a>
            <div class="dropdown">
                <a href="#" class="dropdown-toggle">更多工具
                    <svg class="caret-icon" viewBox="0 0 320 512" xmlns="http://www.w3.org/2000/svg"><path d="M143 352.3L7 216.3c-9.4-9.4-9.4-24.6 0-33.9l22.6-22.6c9.4-9.4 24.6-9.4 33.9 0l96.4 96.4 96.4-96.4c9.4-9.4 24.6-9.4 33.9 0l22.6 22.6c9.4 9.4 9.4 24.6 0 33.9l-136 136c-9.2 9.5-24.4 9.5-33.8 0z"/></svg>
                </a>
                <div class="dropdown-menu">
                    <a href="https://tools.gushiio.com/jianfan">简繁转换</a>
                    <a href="https://tools.gushiio.com/pinyin">汉字转拼音</a>
                    <a href="https://tools.gushiio.com/time">全球实时时间</a>
                    <a href="http://ai.gushiio.com/link_checker.php">批量链接检查</a>
                    <a href="http://ai.gushiio.com/sitemap.php">Sitemap生成器</a>
                </div>
            </div>
            <button type="button" class="btn btn-dark toggle-theme">
                <svg class="icon icon-moon" viewBox="0 0 1024 1024" xmlns="http://www.w3.org/2000/svg"><path d="M530.4 945.4A434.2 434.2 0 0 1 491.5 78.6a41 41 0 0 1 26 70.9 261.7 261.7 0 0 0-83.6 192.3 266.2 266.2 0 0 0 266.2 266.2 262.3 262.3 0 0 0 191.7-82.9s0 1 0 0a41 41 0 0 1 70.7 24.6 434.2 434.2 0 0 1-432.1 395.7z"></path></svg>
                <svg class="icon icon-sun" viewBox="0 0 1024 1024" xmlns="http://www.w3.org/2000/svg" style="display:none;"><path d="M871.5 358.8V155.4H655.4L508.2 11.6 355.4 160.8H147.1V372L0 515.9l152.8 149.3v203.4h216.1l147.2 143.8 152.9-149.3h208.2V651.9l147.1-143.9-147.1-149.2zM512.2 802c-164 0-296.8-129.8-296.8-290 0-160.2 132.9-289.9 296.8-289.9 163.9 0 296.8 129.8 296.8 289.9 0 160.2-132.9 290-296.8 290z"></path><path d="M697.4 511.9c0 100.1-82.9 181.2-185.3 181.2s-185.3-81.1-185.3-181.2c0-100 82.9-181 185.3-181 102.3 0 185.3 81 185.3 181z"></path></svg>
                <span>暗夜模式</span>
            </button>
        </div>
    </header>

    <div class="banner">鼓狮Sitemap生成器</div>

    <div class="container">
        <div class="sitemap-generator-tool">
  
            <form id="sitemapForm" method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                <div class="form-group">
                    <label for="urls">输入起始网址 (每行一个):</label>
                    <textarea id="urls" name="urls" placeholder="例如:&#10;https://data.gushiio.com/&#10;https://tools.gushiio.com/time/" required><?php echo isset($_POST['urls']) ? htmlspecialchars($_POST['urls']) : ""; ?></textarea>
                    <small>请输入网址。程序将从这些页面提取链接。目标域名将从第一个有效网址自动识别。</small>
                </div>
                <div class="form-group">
                    <label for="keywords">过滤条件 (可选, 每行一个):</label>
                    <textarea id="keywords" name="keywords" placeholder="例如:&#10;.html&#10;/article/&#10;news-"><?php echo isset($_POST['keywords']) ? htmlspecialchars($_POST['keywords']) : ''; ?></textarea>
                    <small>获取的网址链接中必须包含指定内容之一，留空则不进行此过滤。</small>
                </div>

                <button type="submit" class="submit-btn" id="generateBtn">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM17 13l-5 5-5-5h3V9h4v4h3z"/></svg>
                    生成Sitemap
                </button>
            </form>

            <div class="progress-bar-container" id="progressContainer">
                <div class="progress-bar" id="progressBar">0%</div>
            </div>
            <div id="progressMessage"></div>

            <?php if (!empty($errorMessage)): ?>
                <div class="error-message"><?php echo $errorMessage; ?></div>
            <?php endif; ?>

            <?php if (!empty($sitemapFilesGenerated) && $_SERVER['REQUEST_METHOD'] == 'POST'): ?>
                <div id="results">
                    <h3>生成结果:</h3>
                    <ul>
                        <?php foreach ($sitemapFilesGenerated as $file): ?>
                            <li>
                                <a href="<?php echo htmlspecialchars($file); ?>" download>
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="18" height="18"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>
                                    <?php echo basename($file); ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </div> <!-- End of container -->

    <div class="footer">
        Copyright &copy; 鼓狮知识库（www.GuShiio.com） 版权所有
        <script>
            var _hmt = _hmt || [];
            (function() {
                var hm = document.createElement("script");
                hm.src = "https://hm.baidu.com/hm.js?32b22a347224cca50c88deb9ffa4250b";
                var s = document.getElementsByTagName("script")[0];
                s.parentNode.insertBefore(hm, s);
            })();
        </script>
    </div>

    <script>
        // --- Theme Toggle & Dropdown ---
        function initializeTheme() {
            const savedTheme = localStorage.getItem('darkMode');
            const isDark = savedTheme === 'true';
            document.body.classList.toggle('dark-mode', isDark);
            const themeButton = document.querySelector('.toggle-theme');
            if (themeButton) {
                const iconMoon = themeButton.querySelector('.icon-moon');
                const iconSun = themeButton.querySelector('.icon-sun');
                const textSpan = themeButton.querySelector('span');
                if (!iconMoon || !iconSun || !textSpan) return;
                if (isDark) {
                    iconMoon.style.display = 'none';
                    iconSun.style.display = 'inline';
                    textSpan.textContent = '日间模式';
                } else {
                    iconMoon.style.display = 'inline';
                    iconSun.style.display = 'none';
                    textSpan.textContent = '暗夜模式';
                }
            }
        }
        function setupThemeToggle() {
            const themeButton = document.querySelector('.toggle-theme');
            if (themeButton) {
                themeButton.addEventListener('click', function() {
                    const isDark = document.body.classList.toggle('dark-mode');
                    localStorage.setItem('darkMode', isDark);
                    const iconMoon = this.querySelector('.icon-moon');
                    const iconSun = this.querySelector('.icon-sun');
                    const textSpan = this.querySelector('span');
                    if (!iconMoon || !iconSun || !textSpan) return;
                    if (isDark) {
                        iconMoon.style.display = 'none';
                        iconSun.style.display = 'inline';
                        textSpan.textContent = '日间模式';
                    } else {
                        iconMoon.style.display = 'inline';
                        iconSun.style.display = 'none';
                        textSpan.textContent = '暗夜模式';
                    }
                });
            }
        }
        function setupDropdown() {
            const dropdownToggle = document.querySelector('.dropdown-toggle');
            const dropdownMenu = document.querySelector('.dropdown-menu');
            if (!dropdownToggle || !dropdownMenu) return;
            dropdownToggle.addEventListener('click', function(e) {
                e.preventDefault();
                dropdownMenu.classList.toggle('active');
            });
            window.addEventListener('click', function(e) {
                if (dropdownToggle && !dropdownToggle.contains(e.target) && dropdownMenu && !dropdownMenu.contains(e.target)) {
                    dropdownMenu.classList.remove('active');
                }
            });
        }

        // --- Sitemap Generator Specific JS ---
        const sitemapForm = document.getElementById('sitemapForm');
        const generateBtn = document.getElementById('generateBtn');
        const progressContainer = document.getElementById('progressContainer');
        const progressBar = document.getElementById('progressBar');
        const progressMessage = document.getElementById('progressMessage');
        let progressInterval;

        function updateProgressDisplay(percentage, message) {
            progressBar.style.width = percentage + '%';
            progressBar.textContent = percentage + '%';
            progressMessage.textContent = message;
            if (percentage > 0 || message || message === '') { // Show if there's progress or any message (even empty to clear old)
                 progressMessage.style.display = 'block';
            }
        }

        function pollProgress() {
            fetch('<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>?action=get_progress&r=' + Math.random()) 
                .then(response => response.json())
                .then(data => {
                    if (data) {
                        updateProgressDisplay(data.percentage || 0, data.message || 'Processing...');
                        if (data.percentage >= 100 && (data.message.toLowerCase().includes("complete") || data.message.toLowerCase().includes("found") || data.message.toLowerCase().includes("no url"))) {
                            clearInterval(progressInterval);
                            generateBtn.disabled = false;
                            generateBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM17 13l-5 5-5-5h3V9h4v4h3z"/></svg> 生成Sitemap';
                             // If form submitted and PHP might have results, don't hide progress yet, let page reload handle it.
                            // However, if it's purely AJAX and generation is done with an error (like "no urls found"),
                            // then we should keep progress message.
                            if (data.message.toLowerCase().includes("no url")) {
                                // Keep message displayed
                            } else if (document.getElementById('results') && document.getElementById('results').innerHTML.trim() === '' && !document.querySelector('.error-message')) {
                                // If no results div populated yet and no error message, maybe hide progress on success if page isn't reloading
                                // progressContainer.style.display = 'none';
                                // progressMessage.style.display = 'none';
                            }
                        }
                    }
                })
                .catch(error => {
                    console.error('Error polling progress:', error);
                });
        }

        if (sitemapForm) {
            sitemapForm.addEventListener('submit', function(e) {
                const resultsDiv = document.getElementById('results');
                if (resultsDiv) resultsDiv.innerHTML = '';
                const errorDiv = document.querySelector('.error-message');
                if (errorDiv) errorDiv.remove();

                generateBtn.disabled = true;
                generateBtn.innerHTML = '<svg class="animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" style="width: 1.2em; height: 1.2em; margin-right: 8px;"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" opacity="0.25"></circle><path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> 生成中...';
                progressContainer.style.display = 'block';
                progressMessage.style.display = 'block'; 
                updateProgressDisplay(0, 'Initializing...'); // Explicitly set initial message

                if(progressInterval) clearInterval(progressInterval);
                progressInterval = setInterval(pollProgress, 1500); 
            });
        }
        
        const styleSheet = document.createElement("style");
        styleSheet.type = "text/css";
        styleSheet.innerText = "@keyframes spin { to { transform: rotate(360deg); } } .animate-spin { animation: spin 1s linear infinite; }";
        document.head.appendChild(styleSheet);

        document.addEventListener('DOMContentLoaded', () => {
            initializeTheme();
            setupThemeToggle();
            setupDropdown();

            // Check if the page loaded with results or an error message from PHP
            const resultsDiv = document.getElementById('results');
            const errorDiv = document.querySelector('.error-message');
             if ((resultsDiv && resultsDiv.innerHTML.trim() !== '') || errorDiv) {
                if(progressInterval) clearInterval(progressInterval); // Stop polling if we have results
                // Hide progress bar elements if results/error are already shown by PHP
                if(progressContainer) progressContainer.style.display = 'none';
                if(progressMessage) progressMessage.style.display = 'none';
            }
        });
    </script>
</body>
</html>

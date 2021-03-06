<?php

/* --------------------------------------------------------------------
  Chevereto
  http://chevereto.com/

  @author	Rodolfo Berrios A. <http://rodolfoberrios.com/>
            <inbox@rodolfoberrios.com>

  Copyright (C) Rodolfo Berrios A. All rights reserved.

  BY USING THIS SOFTWARE YOU DECLARE TO ACCEPT THE CHEVERETO EULA
  http://chevereto.com/license

  --------------------------------------------------------------------- */

namespace CHV;

use G;
use Exception;

if (!defined('access') or !access) {
    die('This file cannot be directly accessed.');
}

try {
    if (!is_null(getSetting('chevereto_version_installed')) and !Login::getUser()['is_admin']) {
        G\set_status_header(403);
        die('Request denied. You must be an admin to be here.');
    }
    if (!class_exists('ZipArchive')) {
        throw new Exception("PHP ZipArchive class is not enabled in this server");
    }
    if (!is_writable(G_ROOT_PATH)) {
        throw new Exception(sprintf("Can't write into root %s path", G\absolute_to_relative(G_ROOT_PATH)));
    }
    $update_temp_dir = CHV_APP_PATH_INSTALL . 'update/temp/';
    if (!is_writable($update_temp_dir)) {
        throw new Exception(sprintf("Can't write into %s path", G\absolute_to_relative($update_temp_dir)));
    }
    if (!isset($_REQUEST['action'])) {
        $doctitle = _s('Update in progress');
        $system_template = CHV_APP_PATH_CONTENT_SYSTEM . 'template.php';
        $update_template = dirname($update_temp_dir) . '/template/update.php';
        if (file_exists($update_template)) {
            ob_start();
            require_once($update_template);
            $html = ob_get_contents();
            ob_end_clean();
        } else {
            throw new Exception("Can't find " . G\absolute_to_relative($update_template));
        }
        if (!@require_once($system_template)) {
            throw new Exception("Can't find " . G\absolute_to_relative($system_template));
        }
    } else {
        $CHEVERETO = Settings::getChevereto();

        set_time_limit(300); // Allow up to five minutes...

        switch ($_REQUEST['action']) {
            case 'ask':
                try {
                    $json_array = json_decode(G\fetch_url($CHEVERETO['api']['get']['info'], false, [
                        CURLOPT_REFERER => G\get_base_url()
                    ]), true);
                    $json_array['success'] = ['message' => 'OK']; // "success" is a Chevereto internal thing
                } catch (Exception $e) {
                    throw new Exception(sprintf('Fatal error: %s', $e->getMessage()), 400);
                }
                break;
            case 'download':
                try {
                    $version = $_REQUEST['version'];
                    $context = stream_context_create([
                        'http'=> [
                            'method' => 'GET',
                            'header' => "User-agent: " . G_APP_GITHUB_REPO . "\r\n"
                            ]
                        ]);
                    $download = @file_get_contents('https://api.github.com/repos/' . G_APP_GITHUB_OWNER . '/' . G_APP_GITHUB_REPO . '/zipball/'.$version, false, $context);
                    if ($download === false) {
                        throw new Exception(sprintf("Can't fetch " . G_APP_NAME . " v%s from GitHub", $version), 400);
                    }
                    $github_json = json_decode($download, true);
                    if (json_last_error() == JSON_ERROR_NONE) {
                        throw new Exception("Can't proceed with update procedure");
                    } else {
                        // Get Content-Disposition header from GitHub
                        foreach ($http_response_header as $header) {
                            if (preg_match('/^Content-Disposition:.*filename=(.*)$/i', $header, $matches)) {
                                $zip_local_filename = G\str_replace_last('.zip', '_' . G\random_string(24) . '.zip', $matches[1]);
                                break;
                            }
                        }
                        if (!isset($zip_local_filename)) {
                            throw new Exception("Can't grab content-disposition header from GitHub");
                        }
                        if (file_put_contents($update_temp_dir . $zip_local_filename, $download) === false) {
                            throw new Exception(_s("Can't save file"));
                        }
                        $json_array = [
                            'success' => [
                                'message'   => 'Download completed',
                                'code'      => 200
                            ],
                            'download' => [
                                'filename' => $zip_local_filename
                            ]
                        ];
                    }
                } catch (Exception $e) {
                    throw new Exception(_s("Can't download %s", $version == 'latest' ? 'Chevereto' : ('v' . $version)) . ' (' . $e->getMessage() . ')');
                }

            break;
            case 'extract':
                $zip_file = $update_temp_dir . $_REQUEST['file'];
                if (false === preg_match('/^(chevereto-chevereto-free)-([\d.]+)-\d+-g(.*)_.*$/i', $_REQUEST['file'], $matches)) {
                    throw new Exception("Can't detect target zip file version");
                }
                $version = $matches[2];
                $etag_short = $matches[3];
                if (!is_readable($zip_file)) {
                    throw new Exception('Missing '.$zip_file.' file', 400);
                }
                $zip = new \ZipArchive;
                if ($zip->open($zip_file) === true) {
                    try {
                        $toggle_maintenance = !getSetting('maintenance');
                        if ($toggle_maintenance) {
                            DB::update('settings', ['value' => $toggle_maintenance], ['name' => 'maintenance']);
                        }
                    } catch (Exception $e) {
                    }

                    $zip->extractTo($update_temp_dir);
                    $zip->close();
                    @unlink($zip_file);
                } else {
                    throw new Exception(_s("Can't extract %s", G\absolute_to_relative($zip_file)), 401);
                }
                // Recursive copy UPDATE -> CURRENT
                $source = $update_temp_dir . $matches[1] . '-' . $etag_short . '/';
                $dest = G_ROOT_PATH;
                foreach ($iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $item) {
                    $target = $dest . $iterator->getSubPathName();
                    $target_visible = G\absolute_to_relative($target);
                    if ($item->isDir()) {
                        if (!file_exists($target) and !@mkdir($target)) {
                            $error = error_get_last();
                            throw new Exception(_s("Can't create %s directory - %e", [
                                '%s' => $target_visible,
                                '%e' => $error['message']
                            ]), 402);
                        }
                    } else {
                        if (!preg_match('/\.htaccess$/', $item)) {
                            if (!@copy($item, $target)) {
                                $error = error_get_last();
                                throw new Exception(_s("Can't update %s file - %e", [
                                    '%s' => $target_visible,
                                    '%e' => $error['message']
                                ]), 403);
                            }
                        }
                        unlink($item);
                    }
                }

                // Remove working copy (UPDATE)
                $tmp = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($update_temp_dir, \RecursiveDirectoryIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($tmp as $fileinfo) {
                    $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
                    $todo($fileinfo->getRealPath());
                }
                try {
                    if ($toggle_maintenance) {
                        DB::update('settings', ['value' => 0], ['name' => 'maintenance']);
                    }
                } catch (Exception $e) {
                }

                $json_array['success'] = ['message' => 'OK', 'code' => 200];
                break;
        }
        // Inject any missing status_code
        if (isset($json_array['success']) and !isset($json_array['status_code'])) {
            $json_array['status_code'] = 200;
        }
        $json_array['request'] = $_REQUEST;
        G\Render\json_output($json_array);
    }
    die();
} catch (Exception $e) {
    if (!isset($_REQUEST['action'])) {
        Render\chevereto_die($e->getMessage(), "This installation can't use the automatic update functionality because this server is missing some crucial elements to allow Chevereto to perform the automatic update:", "Can't perform automatic update");
    } else {
        $json_array = G\json_error($e);
        $json_array['request'] = $_REQUEST;
        G\Render\json_output($json_array);
    }
}

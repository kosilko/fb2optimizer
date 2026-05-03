<?php


function compress($data, $xml_compact = FALSE, $convert_to_1251 = FALSE, $unsafe_convert = FALSE, $jpg_quality = 75, $downscale = 1000) {
  $result = FALSE;
  $flags = !$xml_compact ? LIBXML_BIGLINES : LIBXML_BIGLINES | LIBXML_NOBLANKS;
  libxml_use_internal_errors(true);
  if (!($dom = new DOMDocument())->loadXML($data, $flags | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
    echo "\e[31;47mОШИБКА: содержимое не соответствует формату XML.\e[0m\n";
    libxml_clear_errors();
  }
  else {
    $report = array();
    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('fb', 'http://www.gribuser.ru/xml/fictionbook/2.0');

    
    $binaryNodes = $xpath->query('//fb:binary');
    $hashes = []; // [sha1 => original_id]
    $duplicates = [];  // [duplicate_id => original_id]
    foreach ($binaryNodes as $n) {
      if (($id = $n->getAttribute('id')) . '' !== '') {
        $n->nodeValue = preg_replace('/[\r\n]/', '', trim($n->nodeValue));
        $hash = sha1($n->nodeValue);
        if (!isset($hashes[$hash])) {
          $hashes[$hash] = $id;
        }
        else {
          $duplicates[$id] = $hashes[$hash];
        }      
      }
    }
    if ($duplicates) {
      $report['найдено дубликатов картинок'] = count($duplicates);
    }
    // 2. Исправляем ссылки в тексте (теги <image>)  
    foreach ($xpath->query('//fb:image') as $img) {
      $href = $img->getAttribute($attr = 'l:href') . '';
      $href = $href !== '' ? $href : $img->getAttribute($attr = 'xlink:href') . '';
      if ($href !== '' && ($href = ltrim($href, '#')) !== '') {
        if (isset($duplicates[$href])) {
          $report['исправленные ссылки на картинки'][] = "$href->" . $duplicates[$href];
          $img->setAttribute($attr, '#' . $duplicates[$href]);
        }      
      }
    }

    // 3. Удаляем неиспользуемые binary (и те, что были дублями, и просто лишние)
    // Собираем все ID, которые реально используются в тексте после замены
    $usedIds = [];
    foreach ($xpath->query('//fb:image') as $img) {
      $href = $img->getAttribute('l:href') . '';
      $href = $href !== '' ? $href : $img->getAttribute('xlink:href') . '';
      if ($href !== '' && ($href = ltrim($href, '#')) !== '') {
        $usedIds[$href] = $href;
      }
    }

    // Проходим по всем binary и удаляем пустые, без ID или те, чьих ID нет в списке используемых
    foreach ($binaryNodes as $n) {
      if (($id = $n->getAttribute('id') . '') === '') {
        $n->parentNode->removeChild($n);
        $report['удаленные безымянные картинки'] = ($report['удалено безымянных картинок'] ?? 0) + 1;
      }
      elseif (!$n->nodeValue) {
        $report['удаленные неиспользуемые картинки'][] = "$id";
        $n->parentNode->removeChild($n);
      }
      elseif (!isset($usedIds[$id])) {
        $report['удаленные неиспользуемые картинки'][] = "$id";
        $n->parentNode->removeChild($n);      
      }

    }
    $binaries_backup = array();
    foreach ($xpath->query('//fb:binary') as $n) {
      if (($id = $n->getAttribute('id') . '') !== '') {
        $binaries_backup[$id] = $n->nodeValue;
        $n->nodeValue = '';
      }
    }

    $result = $dom->saveXML();
    if ($convert_to_1251) {
      if (strtolower($dom->encoding) !== 'utf-8') {
        $report['преобразование в windows-1251'] = 'ПРОПУЩЕНО, текущая = ' . ($dom->encoding ?: 'неизвестно');
      }
      elseif (!$unsafe_convert) {
        if (!($new_content = @iconv('UTF-8', 'windows-1251//IGNORE', $result))) {
          $report['преобразование в windows-1251'] = 'ПРОПУЩЕНО, неудача';
        }
        elseif ($result !== @iconv('windows-1251', 'UTF-8', $new_content)) {
          $report['преобразование в windows-1251'] = 'ПРОПУЩЕНО, риск повреждения текста';
        }
        else {
          $result = preg_replace('/(["\'\s]encoding\s*=\s*[\'"])utf-8([\'"])/i', '$1windows-1251$2', $new_content, 1);
          $report['преобразование в windows-1251'] = 'OK, без потерь';
        }
      }
      elseif ($new_content = @iconv('UTF-8', 'windows-1251//TRANSLIT', $result)) {
        $result = preg_replace('/(["\'\s]encoding\s*=\s*[\'"])utf-8([\'"])/i', '$1windows-1251$2', $new_content, 1);
        $report['преобразование в windows-1251'] = 'OK, транслитерировано';
      }
      elseif (!($new_content = @iconv('UTF-8', 'windows-1251//IGNORE', $result))) {
        $report['преобразование в windows-1251'] = 'ПРОПУЩЕНО, неудача';
      }
      elseif ($result !== @iconv('windows-1251', 'UTF-8', $new_content)) {
        $report['преобразование в windows-1251'] = 'ПРОПУЩЕНО, риск повреждения текста';
      }
      else {
        $result = preg_replace('/(["\'\s]encoding\s*=\s*[\'"])utf-8([\'"])/i', '$1windows-1251$2', $new_content, 1);
        $report['преобразование в windows-1251'] = 'OK, без потерь';
      }
    }
    
    if ($binaries_backup) {
      $dom->loadXML($result, $flags);
      $xpath = new DOMXPath($dom);
      $xpath->registerNamespace('fb', 'http://www.gribuser.ru/xml/fictionbook/2.0');
      $passed = array();
      foreach ($xpath->query('//fb:binary') as $n) {
        if (($id = $n->getAttribute('id') . '') !== '' && isset($binaries_backup[$id])) {
          if ($jpg_quality || $downscale) {
            if (!isset($passed[$id])) {
              $passed[$id] = $id;
              $mime = strtolower(trim($n->getAttribute('content-type'))) ?: 'unknown mime type';
              if (($s = base64_decode($binaries_backup[$id])) === FALSE) {
                $report['поврежденные картинки'][] = "$mime $id, ошибка декодирования base64";
              }
              elseif (($imgOrigin = @imagecreatefromstring($s)) === FALSE) {
                if (strpos($mime, 'image/') === 0) {
                  $report['поврежденные картинки'][] = "$mime $id, проблемы с форматом";
                }
                else {
                 $report['неподдерживаемый формат изображения'][] = "$mime $id";
                }
              }
              else {
                $width = imagesx($imgOrigin);
                $height = imagesy($imgOrigin);

                $prev_jpg_quality = $n->getAttribute('jpg-quality') . '';
                $prev_jpg_quality = $prev_jpg_quality && preg_match('/^\d+$/', $prev_jpg_quality) && $prev_jpg_quality >= 0 && $prev_jpg_quality <= 100 ? $prev_jpg_quality : FALSE;

                $newImage = imagecreatetruecolor($width, $height);
                imagefill($newImage, 0, 0, imagecolorallocate($newImage, 255, 255, 255));
                imagecopy($newImage, $imgOrigin, 0, 0, 0, 0, $width, $height);
                imagedestroy($imgOrigin);
                if ($downscale && $width > $downscale) {
                  if (!($downscaledImage = imagescale($newImage, $downscale, -1, ($height > 1) ? IMG_BICUBIC : IMG_NEAREST_NEIGHBOUR))) {
                    $report['не удалось уменьшить'][] = "$mime $id w=$width, h=$height";
                  }
                  else {
                    $newImage = $downscaledImage;
                  }
                }
                ob_start();
                if (imagejpeg($newImage, NULL, $jpg_quality ?: 75)) { // or imagepng, imagewebp, etc.
                  $new_binary = ob_get_contents();
                  if ((strlen($s) / strlen($new_binary)) > 1.05) { // >=5%
                    $n->setAttribute('content-type', 'image/jpeg');
                    $n->setAttribute('jpg-quality', $jpg_quality ?: 75);
                    $binaries_backup[$id] = base64_encode($new_binary);
                    $report['сжатые картинки'][] = "$mime $id w=$width" . 'px' . (!$downscale || $width <= $downscale ? '' : "->$downscale" . 'px');
                  }
                }
                ob_end_clean();
                imagedestroy($newImage);
              }
            }
          }
          $n->nodeValue = $binaries_backup[$id];
        }
      }
    //  $dom->save($new_filename);
      $result = $dom->saveXML();
    }

    $eff = rtrim(round(100 - ((strlen($result) / strlen($data)) * 100), 3), '0.');
    $report['эффективность'] = "$eff%";
    foreach ($report as $title => $item) {
      if (is_scalar($item)) {
        echo "  $title: $item\n";
      }
      else {
        echo "  $title:\n    " .  implode("\n    ", $item) . "\n";
      }
    }      
  }
  return $result;
}

/*
-s, --src - source dir, required
-d, --dest = destination dir, required
-j, --jpeg = convert images to jpeg, quality, 1..100%.
-w, -width = pixels, resize biggest pictures to specified value
-o, --overwrite = overwrite target files, flag
-c, --convert = convert utf8 to 1251
-u, -unsafe = unsafe convert using transliteration. Some symbols can be lost.
-f, --fix = fix xml (remove whitespaces or empty tags)
-z, --zip = pack every file to zip archive
@TODO: --invalid-dest, -i - copy erronymous books to the specified path
*/
function cli_arg($n) {
  $long_opts = array(
    'src:',
    'dest:',
    'jpeg:', 
    'width:', 
    'overwrite',
    'convert', 
    'unsafe',
    'fix',
    'zip',
    'help',
  );
  $short_opts = implode(array_map(fn($s) => preg_replace('/^(.).*?(:*)$/', '$1$2', $s), $long_opts));
  $args = getopt($short_opts, $long_opts);
  $result = NULL;
  foreach ($args as $key => $val) {
    if ($key[0] === $n[0]) {
      if (is_array($val)) {
        $val = end($val);
      }
      $result = $val === FALSE ? TRUE : $val;
    }
  }
  return $result;
  
}

set_exception_handler(function($e) {
    echo "PHP EXCEPTION: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
    return TRUE;
});

set_error_handler(function($code, $msg, $file = NULL, $line = NULL, $ctxt = NULL) {
  echo "PHP ERROR: $code - $msg in $file:$line.\n";
  return TRUE;
});

$input_dir = rtrim(cli_arg('src') . '', '/\\');
$output_dir = rtrim(cli_arg('dest') . '', '/\\');
$overwrite = cli_arg('overwrite');
$pack_to_zip = cli_arg('zip');
$jpg_quality = cli_arg('jpeg');
if ($jpg_quality < 0 || $jpg_quality > 100) {
  echo "ERROR: качество сжатия JPEG должно быть от нуля до 100.\n";
}
elseif ($input_dir  === '') {
  echo "ОШИБКА: Требуется указать путь к папке с оригинальными файлами.";
}
elseif (!file_exists($input_dir)) {
  echo "ОШИБКА: \"$input_dir\" не найден.";
}
elseif (!is_dir($input_dir)) {
  echo "ОШИБКА: \"$input_dir\" не является папкой.";
}
elseif ($output_dir . '' === '') {
  echo "ОШИБКА: Папка назначения не указана.";
}
elseif (is_file($output_dir)) {
  echo "ОШИБКА: Запись в \"$output_dir\" невозможна, укажите другой путь.";
}
elseif (!is_dir($output_dir) && !mkdir($output_dir, 0777, TRUE)) {
  echo "ОШИБКА: Не удалсоь создать папку \"$output_dir\".";
}
elseif (!($tmp = tempnam($output_dir, 'tmp'))) {
  echo "ОШИБКА: Невозможно записать в папку \"$output_dir\", проверьте права доступа.";
}
else {
  @unlink($tmp);
  if (($input_dir = realpath($input_dir)) . '' === '') {
    echo "ОШИБКА: Не удалось определить полный путь к папке \"$input_dir\".";
  }
  elseif (($output_dir = realpath($output_dir)) . '' === '') {
    echo "ОШИБКА: Не удалось определить полный путь к папке \"$output_dir\".";
  }
  else {
    $scandir = function($path, $reset = TRUE) use (&$scandir) {
      static $cnt = 0;
      if ($reset) {
        $cnt = 0;
      }
      $list = array();
      if ($path = !$reset ? $path : realpath(rtrim($path, '/\\'))) {
        foreach (scandir($path) as $item) {
          if (!in_array($item, array('.', '..'), TRUE)) {
            if (is_dir($n = $path . DIRECTORY_SEPARATOR . $item)) {
              $list = array_merge($list, $scandir($n, FALSE));
            }
            elseif (preg_match('/\.(fb2|zip)$/ui', $item)) {
              $list[] = $n;
              $cnt++;
            }
          }
        }          
      }
      echo "\r\033[KНайдено $cnt | $path";     
      return $list;
    };

    $save_to_zip = function($data, $filename, $overwrite = FALSE) {
      $zip = new ZipArchive();
      if (($r = $zip->open($n = preg_replace('/\.fb2$/ui', '.zip', $filename), $overwrite ? ZipArchive::CREATE | ZipArchive::OVERWRITE : ZipArchive::CREATE | ZipArchive::EXCL)) !== TRUE) {
        echo "ОШИБКА: не получилось создать архив $n\n";
        return FALSE;
      }
      else {
        $zip->addFromString(basename($filename), $data, ZipArchive::FL_ENC_UTF_8);
        $zip->close();
        return TRUE;
      }
      
    };
    echo "Сканирую папку \"$input_dir\"...\n";
    $first = TRUE;
    foreach ($scandir($input_dir) as $filename) {
      if ($first) {
        echo "\n\n";
        $first = FALSE;
      }
      if (preg_match('/\.zip/ui', $filename)) {
        echo "Обработка ZIP архива \"$filename\":\n";
        $zip = new ZipArchive();
        if (($r = $zip->open($filename, ZipArchive::RDONLY)) !== TRUE) {
          $zip_err = $r === FALSE ? 'результат = FALSE' : 'ошибка = ' . $r;
          echo "ОШИБКА: Не удалось открыть архив \"$filename\" ($zip_err)\n";
        }
        else {
          $candidates = array('UTF-8', 'CP866', 'Windows-1251', 'CP1251', );
          $cnt = $zip->count();
          for ($i = 0; $i < $cnt; $i++) {
            if ($rawName = $zip->getNameIndex($i, ZipArchive::FL_ENC_RAW)) {
              $encoding = mb_detect_encoding($rawName, $candidates, TRUE);
              
              $correctName = $encoding === 'Windows-1251' || $encoding === 'CP866' ? $rawName :  mb_convert_encoding($rawName, 'UTF-8', $encoding);
              if (preg_match('/\.fb2/iu', $correctName)) {
                $new_filename = $output_dir . DIRECTORY_SEPARATOR . preg_replace('/^' . preg_quote($input_dir, '/') . '[\\\\\/]?/u', '', dirname($filename)) . DIRECTORY_SEPARATOR . $correctName;
                if (!$overwrite && file_exists(!$pack_to_zip ? $new_filename : preg_replace('/\.fb2$/ui', '.zip', $new_filename))) {
                  echo "Файл \"$new_filename\" уже существует, пропущено.\n";
                }
                elseif (!($data = $zip->getFromIndex($i))) {
                  echo $data === FALSE ? "Не получилось извлечь данные из \"$filename:$correctName\".\n" : "Пустой файл \"$filename:$correctName\".\n";
                }
                else {
                  echo "Обработка \"$correctName\":\n";
                  if ($xml = compress(
                    $data,
                    cli_arg('fix'), // compact XML - remove new lines and empty spaces beetween tags
                    cli_arg('convert'), // convert to cp1251
                    cli_arg('unsafe'), // FALSE = safe convert; TRUE = try transliterate unicode symbols
                    $jpg_quality, // jpeg quality
                    cli_arg('width') // max width of pictures
                  )) {
                    if (!is_dir($dir = dirname($new_filename)) && !@mkdir($dir, 0777, TRUE)) {
                      echo "ОШИБКА: Не удалось создать папку \"$dir\" .\n";
                    }
                    elseif ((!$pack_to_zip || !$save_to_zip($xml, $new_filename, $overwrite)) && file_put_contents($new_filename, $xml) === FALSE) {
                      echo "ОШИБКА: Не удалось записать в \"$new_filename\".\n";
                    }
                  }
                  else {
                    echo "НЕУДАЧА\n";
                  }
                }

              }              
            }
          }
          $zip->close();
        }
      }
      else {
        echo "Обработка \"$filename\":\n";
        $new_filename = $output_dir . DIRECTORY_SEPARATOR . preg_replace('/^' . preg_quote($input_dir, '/') . '[\\\\\/]/u', '', $filename);
        if (is_dir($new_filename) || (!$overwrite && file_exists($new_filename))) {
          echo "Файл \"$new_filename\" уже существует, пропущено.\n";
        }
        elseif (!($data = file_get_contents($filename))) {
          if ($data === FALSE) {          
            echo "ОШИБКА: Не удалось прочитать файл \"$filename\"\n";
          }
          else {
            echo "ОШИБКА: Пустой файл \"$filename\".\n";
          }
        }
        else {
          if ($xml = compress(
            $data,
            cli_arg('fix'), // compact XML - remove new lines and empty spaces beetween tags
            cli_arg('convert'), // convert to cp1251
            cli_arg('unsafe'), // FALSE = safe convert; TRUE = try transliterate unicode symbols
            $jpg_quality, // jpeg quality
            cli_arg('width') // max width of pictures
          )) {
            if (!is_dir($dir = dirname($new_filename)) && !@mkdir($dir, 0777, TRUE)) {
              echo "ОШИБКА: Не удалось содать папку \"$dir\".\n";
            }
            elseif ((!$pack_to_zip || !$save_to_zip($xml, $new_filename, $overwrite)) && file_put_contents($new_filename, $xml) === FALSE) {
              echo "ОШИБКА: Не удалось записать в \"$new_filename\".\n";
            }
          }
          else {
            echo "НЕУДАЧА\n";
          }
        }        
      }

      echo "----------------------------------------------------------------\n";
    }
  }

}




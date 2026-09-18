<?php
/**
 * 二维码渲染（基于 qrcode-generator 库）
 * 提供 SVG / 内嵌 HTML / Data-URL PNG 三种输出。
 */

require_once __DIR__ . '/vendor/qrcode.php';

class QR
{
    /** 渲染为 SVG 字符串 */
    public static function svg($content, $size = 4, $color = '#111827', $bg = '#ffffff')
    {
        $qr = self::make($content);
        $n  = $qr->getModuleCount();
        $dim = $n * $size;
        $out = '<svg width="' . $dim . '" height="' . $dim . '" viewBox="0 0 ' . $dim . ' ' . $dim . '" xmlns="http://www.w3.org/2000/svg" shape-rendering="crispEdges">';
        $out .= '<rect width="' . $dim . '" height="' . $dim . '" fill="' . $bg . '"/>';
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                if ($qr->isDark($r, $c)) {
                    $out .= '<rect x="' . ($c * $size) . '" y="' . ($r * $size) . '" width="' . $size . '" height="' . $size . '" fill="' . $color . '"/>';
                }
            }
        }
        $out .= '</svg>';
        return $out;
    }

    /** 渲染为 base64 PNG Data-URL（供 <img> 使用） */
    public static function pngDataUrl($content, $size = 8, $color = array(17, 24, 39), $bg = array(255, 255, 255))
    {
        $qr = self::make($content);
        $n  = $qr->getModuleCount();
        $dim = $n * $size;
        $img = imagecreatetruecolor($dim, $dim);
        if ($img === false) {
            throw new RuntimeException('GD 不可用，无法生成二维码 PNG');
        }
        $bgCol   = imagecolorallocate($img, $bg[0], $bg[1], $bg[2]);
        $fgCol   = imagecolorallocate($img, $color[0], $color[1], $color[2]);
        imagefilledrectangle($img, 0, 0, $dim, $dim, $bgCol);
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                if ($qr->isDark($r, $c)) {
                    imagefilledrectangle($img, $c * $size, $r * $size, $c * $size + $size - 1, $r * $size + $size - 1, $fgCol);
                }
            }
        }
        ob_start();
        imagepng($img);
        $png = ob_get_clean();
        imagedestroy($img);
        return 'data:image/png;base64,' . base64_encode($png);
    }

    /** 渲染为内嵌 HTML（table 方式，用于 CLI 里的简单展示） */
    public static function html($content, $size = 4, $color = '#111827', $bg = '#ffffff')
    {
        $qr = self::make($content);
        $n  = $qr->getModuleCount();
        $out = '<table style="border-collapse:collapse;margin:0;padding:0;background:' . $bg . '">';
        for ($r = 0; $r < $n; $r++) {
            $out .= '<tr>';
            for ($c = 0; $c < $n; $c++) {
                $col = $qr->isDark($r, $c) ? $color : $bg;
                $out .= '<td style="width:' . $size . 'px;height:' . $size . 'px;background:' . $col . '"></td>';
            }
            $out .= '</tr>';
        }
        $out .= '</table>';
        return $out;
    }

    /** 终端里用 ANSI 色块打印二维码（无 GUI 时的兜底） */
    public static function ansi($content)
    {
        $qr = self::make($content);
        $n  = $qr->getModuleCount();
        $out = '';
        for ($r = 0; $r < $n; $r += 2) {
            for ($c = 0; $c < $n; $c++) {
                $top = $qr->isDark($r, $c);
                $bot = ($r + 1 < $n) ? $qr->isDark($r + 1, $c) : false;
                if ($top && $bot) {
                    $out .= ' ';       // 全黑：空白块（反白）
                } elseif ($top) {
                    $out .= "\033[97m\033[40m▀\033[0m"; // 上黑下白
                } elseif ($bot) {
                    $out .= "\033[97m\033[40m▄\033[0m"; // 上白下黑
                } else {
                    $out .= "\033[37m\033[47m \033[0m";
                }
            }
            $out .= "\n";
        }
        return $out;
    }

    private static function make($content)
    {
        $qr = new QRCode();
        $qr->setErrorCorrectLevel(QR_ERROR_CORRECT_LEVEL_M);
        $content = (string)$content;
        $len = strlen($content);
        // 根据数据长度自动选择版本（8-bit byte 模式容量表）
        $typeNumber = 0;
        for ($t = 1; $t <= 10; $t++) {
            // QR_MAX_LENGTH[typeNumber-1][level][mode]；level 索引：0=L,1=M,2=Q,3=H；mode 索引 2=8bit
            $cap = QRUtil::$QR_MAX_LENGTH[$t - 1][1][2];
            if ($cap >= $len) {
                $typeNumber = $t;
                break;
            }
        }
        if ($typeNumber === 0) {
            $typeNumber = 10;
        }
        $qr->setTypeNumber($typeNumber);
        $qr->addData($content, QR_MODE_8BIT_BYTE);
        $qr->make();
        return $qr;
    }
}

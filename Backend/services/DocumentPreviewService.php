<?php

class DocumentPreviewService
{
    private const OFFICE_EXTENSIONS = ['doc', 'docx', 'ppt', 'pptx'];

    public function shouldConvertToPdf(string $filePath): bool
    {
        $ext = strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION));
        return in_array($ext, self::OFFICE_EXTENSIONS, true);
    }

    public function convertOfficeToPdf(string $inputPath, string $backendRoot): ?string
    {
        if (!$this->shouldConvertToPdf($inputPath) || !is_file($inputPath)) {
            return null;
        }

        $uploadsRoot = realpath($backendRoot . DIRECTORY_SEPARATOR . 'uploads');
        if ($uploadsRoot === false) {
            return null;
        }

        $cacheDir = $uploadsRoot . DIRECTORY_SEPARATOR . 'previews';
        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
            return null;
        }

        $mtime = @filemtime($inputPath) ?: 0;
        $hash = sha1($inputPath . '|' . $mtime);
        $cachedPdf = $cacheDir . DIRECTORY_SEPARATOR . $hash . '.pdf';
        if (is_file($cachedPdf)) {
            return $cachedPdf;
        }

        $tempDir = $cacheDir . DIRECTORY_SEPARATOR . 'tmp_' . $hash;
        if (!is_dir($tempDir) && !mkdir($tempDir, 0755, true) && !is_dir($tempDir)) {
            return null;
        }

        $soffice = $this->resolveSofficePath();
        if ($soffice === null) {
            return null;
        }

        $escapedSoffice = escapeshellarg($soffice);
        $escapedInput = escapeshellarg($inputPath);
        $escapedOutDir = escapeshellarg($tempDir);

        $command = "{$escapedSoffice} --headless --convert-to pdf --outdir {$escapedOutDir} {$escapedInput}";
        @exec($command, $output, $exitCode);

        if ((int) $exitCode !== 0) {
            return null;
        }

        $convertedName = pathinfo($inputPath, PATHINFO_FILENAME) . '.pdf';
        $convertedPath = $tempDir . DIRECTORY_SEPARATOR . $convertedName;
        if (!is_file($convertedPath)) {
            return null;
        }

        if (!@rename($convertedPath, $cachedPdf)) {
            if (!@copy($convertedPath, $cachedPdf)) {
                return null;
            }
        }

        return is_file($cachedPdf) ? $cachedPdf : null;
    }

    private function resolveSofficePath(): ?string
    {
        $configured = getenv('ETUTOR_SOFFICE_PATH');
        if (is_string($configured) && trim($configured) !== '' && is_file(trim($configured))) {
            return trim($configured);
        }

        $candidates = [
            'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
            'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe'
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}


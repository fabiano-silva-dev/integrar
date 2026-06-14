<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class OperadoraStorage
{
    public static function diskPath(string $subdir, ?int $operadoraId = null): string
    {
        $id = $operadoraId ?? OperadoraContext::id();

        if ($id !== null) {
            return "{$id}/{$subdir}";
        }

        return $subdir;
    }

    public static function ensureDirectory(string $subdir, ?int $operadoraId = null): string
    {
        $path = self::diskPath($subdir, $operadoraId);
        Storage::makeDirectory($path);

        return $path;
    }

    public static function tempDirectory(?int $operadoraId = null): string
    {
        return Storage::path(self::ensureDirectory('temp', $operadoraId));
    }

    public static function exportsDirectory(?int $operadoraId = null): string
    {
        return Storage::path(self::ensureDirectory('exports', $operadoraId));
    }

    public static function absolutePath(string $subdir, string $filename, ?int $operadoraId = null): string
    {
        return Storage::path(self::diskPath($subdir, $operadoraId) . '/' . basename($filename));
    }

    public static function put(string $subdir, string $filename, mixed $contents, ?int $operadoraId = null): string
    {
        $dir = self::ensureDirectory($subdir, $operadoraId);
        $relative = "{$dir}/" . basename($filename);
        Storage::put($relative, $contents);

        return $relative;
    }

    public static function exists(string $subdir, string $filename, ?int $operadoraId = null): bool
    {
        return Storage::exists(self::diskPath($subdir, $operadoraId) . '/' . basename($filename));
    }

    public static function download(string $subdir, string $filename, ?int $operadoraId = null)
    {
        $relative = self::resolveRelativePath($subdir, $filename, $operadoraId);

        if ($relative === null) {
            abort(404, 'Arquivo não encontrado.');
        }

        return Storage::download($relative, basename($filename));
    }

    public static function resolveRelativePath(string $subdir, string $filename, ?int $operadoraId = null): ?string
    {
        $filename = basename($filename);
        $id = $operadoraId ?? OperadoraContext::id();

        if ($id !== null) {
            $tenantPath = "{$id}/{$subdir}/{$filename}";
            if (Storage::exists($tenantPath)) {
                return $tenantPath;
            }
        }

        $legacyPath = "{$subdir}/{$filename}";

        return Storage::exists($legacyPath) ? $legacyPath : null;
    }

    public static function resolveAbsolutePath(string $subdir, string $filename, ?int $operadoraId = null): ?string
    {
        $relative = self::resolveRelativePath($subdir, $filename, $operadoraId);

        return $relative ? Storage::path($relative) : null;
    }

    public static function delete(string $relativePath): void
    {
        if (Storage::exists($relativePath)) {
            Storage::delete($relativePath);
        }
    }
}

<?php
declare(strict_types=1);

/**
 * The media library's actions (JSON, for the library pages): upload,
 * rename and set a sound's segments, delete. A user's own media by
 * default; with "shared", the media everyone can use, which only
 * administrators change. See MediaLibrary.
 */
final class MediaController
{
    /** POST /media-library — one file, with its length when it is a sound. */
    public static function upload(): void
    {
        Auth::requireLogin();
        Csrf::verify(json: true);

        $shared = !empty($_POST['shared']);

        if ($shared && !Auth::isAdmin()) {
            json_response(['ok' => false, 'error' => __('ui.message.media_shared_admin')], 403);
        }

        $file = $_FILES['file'] ?? null;

        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            $big = in_array($file['error'] ?? null, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
            json_response(['ok' => false, 'error' => $big
                ? sprintf(__('ui.message.media_too_big'), intdiv(max(OverlayConfig::maxBytes('sound'), OverlayConfig::maxBytes('image')), 1024 * 1024))
                : __('ui.message.media_not_supported')], 400);
        }

        $duration = is_numeric($_POST['duration'] ?? null) ? (float) $_POST['duration'] : null;

        try {
            $asset = MediaLibrary::store($shared ? null : (int) Auth::id(), (string) file_get_contents($file['tmp_name']), (string) ($file['name'] ?? ''), $duration);
        } catch (UserError $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 400);
        }

        json_response(['ok' => true, 'asset' => $asset, 'usage' => $shared ? null : MediaLibrary::usage((int) Auth::id())]);
    }

    /** POST /media-library/update — name, and for a sound its segments (a JSON list). */
    public static function update(): void
    {
        Auth::requireLogin();
        Csrf::verify(json: true);

        $segments = json_decode((string) ($_POST['segments'] ?? '[]'), true);

        try {
            $asset = MediaLibrary::update(
                filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0,
                (int) Auth::id(),
                Auth::isAdmin(),
                (string) ($_POST['name'] ?? ''),
                is_array($segments) ? $segments : []
            );
        } catch (UserError $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 400);
        }

        json_response(['ok' => true, 'asset' => $asset]);
    }

    /** POST /media-library/delete */
    public static function delete(): void
    {
        Auth::requireLogin();
        Csrf::verify(json: true);

        try {
            MediaLibrary::delete(filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0, (int) Auth::id(), Auth::isAdmin());
        } catch (UserError $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 400);
        }

        json_response(['ok' => true, 'usage' => MediaLibrary::usage((int) Auth::id())]);
    }
}

<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Shared logo upload handling for the platform and courier catalog forms.
 *
 * Seeded logos are paths under `assets/images/...` that ship with the repo
 * and are shared across environments, so they are never deleted — only
 * files this panel uploaded (which live under the given storage directory)
 * are cleaned up, mirroring how ProfileController treats preset avatars.
 */
trait StoresCatalogLogo
{
    /**
     * Resolve the new value for a model's logo column from the request:
     * an uploaded file, an explicit removal, or no change at all.
     *
     * Returns a tuple of [shouldChange, newValue] rather than just a value,
     * so "leave it alone" stays distinguishable from "set it to null".
     *
     * @return array{bool, string|null}
     */
    protected function resolveLogo(Request $request, Model $model, string $column, string $directory): array
    {
        if ($request->hasFile('logo')) {
            // Written before the old file is removed: store() returns false
            // on a failed disk write, and deleting first would leave the
            // model pointing at a file that no longer exists.
            $path = $request->file('logo')->store($directory, 'public');

            if ($path === false) {
                throw new RuntimeException('The logo could not be stored.');
            }

            $this->deleteUploadedLogo($model, $column, $directory);

            return [true, $path];
        }

        if ($request->boolean('remove_logo')) {
            $this->deleteUploadedLogo($model, $column, $directory);

            return [true, null];
        }

        return [false, null];
    }

    /**
     * Delete a previously uploaded logo file. Uses the raw column value
     * because the model accessor turns it into a public URL.
     */
    protected function deleteUploadedLogo(Model $model, string $column, string $directory): void
    {
        $path = $model->getRawOriginal($column);

        if ($path && str_starts_with($path, $directory.'/')) {
            Storage::disk('public')->delete($path);
        }
    }
}

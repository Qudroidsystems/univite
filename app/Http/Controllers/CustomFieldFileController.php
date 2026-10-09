<?php

namespace App\Http\Controllers;

use App\Models\CustomFieldValue;
use App\Support\CustomFields;
use Illuminate\Support\Facades\Storage;

class CustomFieldFileController extends Controller
{
    /** Files are kept on private storage and only handed to people allowed to see that record. */
    public function download(CustomFieldValue $value)
    {
        abort_unless($value->file_path && CustomFields::canDownload(auth()->user(), $value), 404);

        return response()->file(Storage::disk('local')->path($value->file_path), [
            'Content-Disposition' => 'inline; filename="' . addslashes($value->original_name ?? 'file') . '"',
        ]);
    }
}

<?php

namespace App\Support;

/** The company logo, for places that cannot use a URL (PDFs, emails). */
class Brand
{
    /**
     * The logo as a data URI. dompdf resolves remote URLs unreliably on
     * shared hosting, so the bytes are embedded instead.
     *
     * Prefers the small copy: the full logo-mark is 800px and a few hundred
     * KB, which base64-encoded would turn every one-page PDF into a heavy
     * download for an agent to share over mobile data.
     */
    public static function logoDataUri(): ?string
    {
        foreach (['images/logo-receipt.png', 'images/logo-mark.png'] as $candidate) {
            $path = public_path($candidate);

            if (is_file($path)) {
                return 'data:image/png;base64,'.base64_encode((string) file_get_contents($path));
            }
        }

        return null;
    }

    public static function companyName(): string
    {
        return (string) Settings::get('general.company_name', config('app.name'));
    }
}

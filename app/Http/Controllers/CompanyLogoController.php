<?php

namespace Crater\Http\Controllers;

use Crater\Models\Company;

class CompanyLogoController extends Controller
{
    /**
     * Stream the company logo from the media library disk.
     */
    public function __invoke(Company $company)
    {
        $logo = $company->getLogoMediaContents();

        if (! $logo) {
            abort(404);
        }

        return response($logo['data'], 200, [
            'Content-Type' => $logo['mime'],
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}

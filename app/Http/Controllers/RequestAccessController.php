<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class RequestAccessController extends Controller
{
    public function __invoke(Request $request)
    {
        // This will render the Filament page via Livewire
        $component = \Livewire\Livewire::mount(\App\Filament\Pages\Auth\RequestAccess::class);

        return response($component)->withHeaders(['X-Livewire' => 'true']);
    }
}

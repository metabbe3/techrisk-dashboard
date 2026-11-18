<x-mail::message>
{{-- The pre-substituted template body: values are HTML-escaped in
InvestigationFormSettings::render, the markdown itself is converted with
html_input=escape so admin-typed raw HTML can't inject either. --}}
{!! Illuminate\Support\Str::markdown($body, ['html_input' => 'escape']) !!}
</x-mail::message>

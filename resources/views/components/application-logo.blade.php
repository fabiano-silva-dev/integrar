@props([
    'variant' => 'default', // default | icon
])

@php
    [$src, $srcset] = match ($variant) {
        'icon' => [
            asset('images/brand/icon.png'),
            asset('images/brand/icon@2x.png') . ' 2x, ' . asset('images/brand/icon@3x.png') . ' 3x',
        ],
        default => [
            asset('images/brand/logo.png'),
            asset('images/brand/logo@2x.png') . ' 2x, ' . asset('images/brand/logo@3x.png') . ' 3x',
        ],
    };
    $class = match ($variant) {
        'icon' => 'h-10 w-10',
        default => 'h-14 w-auto max-w-[320px]',
    };
@endphp

<img {{ $attributes->merge(['class' => $class]) }} src="{{ $src }}" srcset="{{ $srcset }}" alt="IntegraExpert">

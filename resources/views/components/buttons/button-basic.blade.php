@props(['mode' => 'basic', 'size' => 'md'])
@php

$modeClasses = match ($mode) {
    'primary' => ' text-white bg-blue-500 hover:bg-blue-600 focus:ring-blue-300 border-blue-600',  
    'secondary' => ' text-gray-700 bg-gray-200 hover:bg-gray-300 focus:ring-gray-400 border-gray-300',
    'danger' => ' text-white bg-red-600 hover:bg-red-700 focus:ring-red-300 border-red-600',
    'success' => ' text-white bg-green-600 hover:bg-green-700 focus:ring-green-300 border-green-600',
    'warning' => ' text-white bg-yellow-600 hover:bg-yellow-700 focus:ring-yellow-300 border-yellow-600',
    'info' => ' text-white bg-teal-600 hover:bg-teal-700 focus:ring-teal-300 border-teal-600',
    'light' => ' text-gray-900 bg-gray-100 hover:bg-gray-200 focus:ring-gray-300 border-gray-100',
    'dark' => ' text-white bg-gray-800 hover:bg-gray-900 focus:ring-gray-700 border-gray-800',
    'link' => ' text-blue-600 bg-transparent hover:bg-blue-100 focus:ring-blue-200 border-transparent',
    'basic' => ' text-gray-900 bg-white hover:bg-gray-200 focus:ring-gray-100 border-gray-300',
};

$sizeClasses = match ($size) {
    'sm' => 'px-2 py-1 text-sm',
    'md' => 'px-4 py-2 text-base',
    'lg' => 'px-5 py-3 text-lg',
    'xl' => 'px-6 py-4 text-xl',
    '2xl' => 'px-7 py-5 text-2xl',
};

 $classes = $modeClasses . ' ' . $sizeClasses;
 $classes .=  ' transition-all duration-100 inline-flex items-center justify-center text-center border rounded-lg focus:ring-4 ';

@endphp

@if (isset($attributes['href']))
    <a {!! $attributes->merge(['class' => $classes]) !!} x-data="{ isClicked: false }" 
        @click="isClicked = true; setTimeout(() => isClicked = false, 100)"
        style="transform:scale(1);"
        :style="isClicked ? 'transform:scale(0.9);' : ''"
        wire:navigate >
        {{ $slot }}
    </a>
@else
    <button {!! $attributes->merge(['class' => $classes]) !!} x-data="{ isClicked: false }" 
        @click="isClicked = true; setTimeout(() => isClicked = false, 100)"
        style="transform:scale(1);"
        :style="isClicked ? 'transform:scale(0.9);' : ''">
        {{ $slot }}
    </button>
@endif
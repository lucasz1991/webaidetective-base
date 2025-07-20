<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" style="user-select:none;" class="scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        
        <x-meta-page-header />
        <title>@yield('title') | {{ config('app.name') }}</title>
        <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('site-images/favicon/favicon.jpg') }}">
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('site-images/favicon/favicon.jpg') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('site-images/favicon/favicon.jpg') }}">

        <link rel="stylesheet" href="/adminresources/css/swiper-bundle.min.css">
        <script src="/adminresources/js/swiper-bundle.min.js"></script>
        <link href="{{ URL::asset('adminresources/flatpickr/flatpickr.min.css') }}" rel="stylesheet" type="text/css" />
        <link href="{{ URL::asset('adminresources/choices.js/public/assets/styles/choices.min.css') }}" rel="stylesheet" type="text/css" />
        <script src="{{ URL::asset('adminresources/choices.js/public/assets/scripts/choices.min.js') }}"></script>
        <script src="{{ URL::asset('adminresources/flatpickr/flatpickr.min.js') }}"></script>
        <script src="{{ URL::asset('adminresources/flatpickr/l10n/de.js') }}"></script>
        <link rel="stylesheet" href="{{ URL::asset('adminresources/metismenujs/metismenujs.min.css') }}">

        
        <!-- Styles -->
        @vite(['resources/css/app.css','resources/scss/sidebar.scss'])

        <!-- Styles -->
        @livewireStyles
    </head>
    
        <body data-mode="light" data-sidebar-size="lg" class="group font-notosans">
        <!-- sidebar -->
        @include('layouts.sidebar')
        <!-- topbar -->
        @include('layouts.topbar')
        <!-- content -->
        @yield('content')
        <!-- Page Content -->
        @if(isset($slot))
            <main class="bg-gray-100">
                <div class="main-content group-data-[sidebar-size=sm]:ml-[70px]">
                    <div class="min-h-screen page-content px-1" style="box-shadow: inset 0px 80px 30px -10px rgba(0, 0, 0, 0.2);">
                        <div class="container-fluid px-0 md:px-5">
                            <!-- Page header -->
                            @if (isset($header))
                                <header class=" mb-4">
                                        {{ $header }}
                                </header>
                            @endif
                            <div class=" @if(Route::currentRouteName() !== 'dashboard') bg-white rounded-md border border-gray-200 p-4  @endif ">
                                {{ $slot }}
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        @endif
        <!-- script -->
        @include('layouts.vendor-scripts')
        <!-- Scripts -->
        @vite(['resources/js/app.js'])
        @livewireScripts
        @yield('js')
    </body>
</html>

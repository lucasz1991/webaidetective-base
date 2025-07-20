import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import typography from '@tailwindcss/typography';
import fs from 'fs';
import path from 'path';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './vendor/laravel/jetstream/**/*.blade.php',
        './storage/framework/views/*.php',
        ...getAllCacheFiles('./storage/framework/cache/data/'),
        './resources/views/**/*.blade.php',
        './resources/views/**/**/*.blade.php',
        './resources/views/**/**/**/*.blade.php',
        './app/Http/Livewire/**/*.php',
        './app/**/*.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Quicksand', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                primary: {
                    DEFAULT: '#2ea640',
                    light: '#0b5879',
                    dark: '#084058',
                    50: '#eefaff',
                    100: '#94defd',
                    200: '#75cdf3',
                    300: '#46b6e6',
                    400: '#289aca',
                    500: '#1488b9',
                    600: '#1075a0',
                    700: '#0d648a',
                    800: '#0b5879',
                    900: '#2ea640',
                },
                secondary: {
                    DEFAULT: '#1e40af',
                    light: '#10b3aa',
                    dark: '#0c968e',
                    50: '#e6ecff',
                    100: '#b3fffb',
                    200: '#2fcec6', 
                    300: '#2fcec6',
                    400: '#2fcec6',
                    500: '#2fcec6',
                    600: '#2fcec6',
                    700: '#2fcec6',
                    800: '#10b3aa',
                    900: '#1e40af',
                },
                transparent: {
                    DEFAULT: '#fff0',
                },
            },
        },
    },

    plugins: [forms, typography],
    safelist: [
        'bg-green-500', 
        'bg-yellow-500', 
        'bg-red-500', 
        'bg-blue-500', 
        'bg-white',
        'fill-green-500',
        'fill-yellow-500', 
        'fill-red-500', 
        'fill-blue-500', 
        'fill-white',
      ],
};
function getAllCacheFiles(dir, fileList = []) {
    try {
        const files = fs.readdirSync(dir);
        files.forEach(file => {
            const filePath = path.join(dir, file);
            if (fs.statSync(filePath).isDirectory()) {
                getAllCacheFiles(filePath, fileList); // Rekursive Suche in Unterordnern
            } else {
                fileList.push(filePath); // Datei zur Liste hinzufügen
            }
        });
    } catch (err) {
        console.error("Fehler beim Lesen der Cache-Dateien:", err);
    }
    return fileList;
}
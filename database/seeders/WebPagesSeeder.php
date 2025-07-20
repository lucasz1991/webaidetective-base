<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\WebPage;
use App\Models\PagebuilderProject;

class WebPagesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $fixedPages = [
            ['id' => 1, 'title' => 'Qualiprogramm', 'slug' => 'start'],
            ['id' => 2, 'title' => 'Login', 'slug' => 'login'],
            ['id' => 3, 'title' => 'Register', 'slug' => 'register'],
            ['id' => 4, 'title' => 'Passwort zurücksetzen', 'slug' => 'passwordreset'],
            ['id' => 5, 'title' => '404 - Seite nicht gefunden', 'slug' => '404'],
            
            ['id' => 6, 'title' => 'Kontakt', 'slug' => 'contact'],
            ['id' => 7, 'title' => 'FAQs', 'slug' => 'faqs'],
            ['id' => 8, 'title' => 'So funktionierts', 'slug' => 'howto'],
            ['id' => 10, 'title' => 'Bewertungen', 'slug' => 'bewertungen'],
            ['id' => 11, 'title' => 'Konto', 'slug' => 'dashboard'],
            ['id' => 12, 'title' => 'Fehlzeiten', 'slug' => 'absences-create'],
            ['id' => 13, 'title' => 'Nachprüfung', 'slug' => 'makeup-exam-create'],

        ];

        foreach ($fixedPages as $pageData) {
            $page = WebPage::firstOrCreate(
                ['id' => $pageData['id']],
                [
                    'title' => $pageData['title'],
                    'slug' => $pageData['slug'],
                    'meta_title' => $pageData['title'],
                    'is_fixed' => true,
                    'is_active' => true,
                    'settings' => [
                        'showHeader' => true,
                    ],
                ]
            );

            if (!$page->pagebuilder_project) {
                $randomNumber = rand(1000, 9999);
                $projectName = "{$page->title} Content";

                $projectData = '{"assets":[],"styles":[],"pages":[{"frames":[{"component":{"type":"wrapper","attributes":{"id":"itix"},"components":[{"tagName":"section","classes":["text-gray-600","body-font","relative"],"attributes":{"id":"iyduu"},"components":[{"classes":["container","px-5","py-24","mx-auto"],"attributes":{"id":"i91ng"},"components":[{"classes":["flex","flex-col","text-center","w-full","mb-12"],"attributes":{"id":"in4uu"},"components":[{"type":"heading","classes":["sm:text-3xl","text-2xl","font-medium","title-font","mb-4","text-gray-900"],"attributes":{"id":"igmy6"},"components":[{"type":"textnode","content":"Neues Pagebuilder Project"}]},{"tagName":"p","type":"text","classes":["lg:w-2/3","mx-auto","leading-relaxed","text-base"],"attributes":{"id":"i0w6e"},"components":[{"type":"textnode","content":"Hier kannst du kreativ werden und deine Träume verwirklichen!"}]}]}]}]}],"doctype":"<!DOCTYPE html>","head":{"type":"head","components":[{"tagName":"meta","void":true,"attributes":{"charset":"utf-8"}},{"tagName":"meta","void":true,"attributes":{"name":"viewport","content":"width=device-width,initial-scale=1"}},{"tagName":"meta","void":true,"attributes":{"name":"robots","content":"index,follow"}},{"tagName":"meta","void":true,"attributes":{"name":"generator","content":"LMZ Studio Project"}},{"tagName":"link","type":"link","attributes":{"href":"https://cbw-weiterbildung-schulnetz.shopspaze.com/adminresources/css/tailwind.min.css","rel":"stylesheet"}}]},"docEl":{"tagName":"html"}},"id":"8uKM3pEMmO8ZbWvE"}],"type":"main","id":"BGeRYNcKhJpNIMjv"}],"symbols":[],"dataSources":[],"custom":{"projectType":"web","id":""}}';

                $maxOrderId = PagebuilderProject::max('order_id') ?? 0;
                $order = $maxOrderId + 1;

                $project = PagebuilderProject::create([
                    'name' => $projectName,
                    'data' => $projectData,
                    'status' => 3,
                    'page' => [$page->slug],
                    'position' => ['page'],
                    'order_id' => $order,
                    'type' => 'page',
                ]);

                $page->pagebuilder_project = $project->id;
                $page->saveQuietly();
            }
        }
    }
}

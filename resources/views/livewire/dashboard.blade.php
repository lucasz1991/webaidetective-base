<div class="w-full relative bg-cover bg-center bg-gray-100 pb-20 pt-8" wire:loading.class="cursor-wait">
    <div class="container mx-auto px-5" >
            <div x-data="{ selectedTab: 'basic' }" class="w-full">
                <div x-on:keydown.right.prevent="$focus.wrap().next()" x-on:keydown.left.prevent="$focus.wrap().previous()" class="flex gap-2 overflow-x-auto border-b border-outline dark:border-outline-dark" role="tablist" aria-label="tab options">
                    <button x-on:click="selectedTab = 'basic'" x-bind:aria-selected="selectedTab === 'basic'" x-bind:tabindex="selectedTab === 'basic' ? '0' : '-1'" x-bind:class="selectedTab === 'basic' ? 'bg-white rounded-t-lg shadow font-bold text-primary border-b-2 border-secondary dark:border-primary-dark dark:text-primary-dark' : 'text-on-surface font-medium dark:text-on-surface-dark dark:hover:border-b-outline-dark-strong dark:hover:text-on-surface-dark-strong hover:border-b-2 hover:border-b-outline-strong hover:text-on-surface-strong'" class="h-min px-4 py-2 text-sm" type="button" role="tab" aria-controls="tabpanelBasic" >Allgemein</button>
                    <button x-on:click="selectedTab = 'abos'" x-bind:aria-selected="selectedTab === 'abos'" x-bind:tabindex="selectedTab === 'abos' ? '0' : '-1'" x-bind:class="selectedTab === 'abos' ? 'bg-white rounded-t-lg font-bold text-primary border-b-2 border-secondary dark:border-primary-dark dark:text-primary-dark' : 'text-on-surface font-medium dark:text-on-surface-dark dark:hover:border-b-outline-dark-strong dark:hover:text-on-surface-dark-strong hover:border-b-2 hover:border-b-outline-strong hover:text-on-surface-strong'" class="h-min px-4 py-2 text-sm" type="button" role="tab" aria-controls="tabpanelAbos" >Fehlzeiten</button>
                    <button x-on:click="selectedTab = 'verification'" x-bind:aria-selected="selectedTab === 'verification'" x-bind:tabindex="selectedTab === 'verification' ? '0' : '-1'" x-bind:class="selectedTab === 'verification' ? 'bg-white rounded-t-lg font-bold text-primary border-b-2 border-secondary dark:border-primary-dark dark:text-primary-dark' : 'text-on-surface font-medium dark:text-on-surface-dark dark:hover:border-b-outline-dark-strong dark:hover:text-on-surface-dark-strong hover:border-b-2 hover:border-b-outline-strong hover:text-on-surface-strong'" class="h-min px-4 py-2 text-sm" type="button" role="tab" aria-controls="tabpanelVerification" >Nachprüfungen</button>
                </div>
                <div class="px-4 py-12 text-on-surface bg-white shadow-lg rounded-lg " >
                    <div x-cloak x-show="selectedTab === 'basic'" id="tabpanelGroups" role="tabpanel" aria-label="basic">
                        <div class="mr-auto font-semibold text-2xl ">
                            <h1 class="max-w-2xl mb-4 font-bold tracking-tight leading-none text-2xl xl:text-3xl">
                                Willkommen {{ $userData->name }},
                            </h1>
                            <p class="max-w-2xl mb-6 text-gray-500 md:text-lg lg:text-xl">
                                Hier können Sie Ihre Klausurergebnisse einsehen, Fehlermeldungen zu Ihren Prüfungen melden sowie Anträge rund um Ihre Prüfungen und Ergebnisse stellen und den Bearbeitungsstand verfolgen. Nutzen Sie dieses Dashboard, um stets den Überblick über Ihre Prüfungsangelegenheiten zu behalten.
                            </p>
                        </div>
                    
                        
                    
               
                        
                    </div>
                    <div x-cloak x-show="selectedTab === 'abos'" id="tabpanelLikes" role="tabpanel" aria-label="likes">
                        Hier werden Ihre vergangenen Meldungen von Fehlzeiten aufgelistet. Sie können hier nachvollziehen, welche Fehlzeiten Sie bereits gemeldet haben und den Status der jeweiligen Meldungen einsehen.
                    </div>
                    <div x-cloak x-show="selectedTab === 'verification'" id="tabpanelComments" role="tabpanel" aria-label="verification">
                        Hier werden Ihre vergangenen Nachprüfungsanträge aufgelistet. Sie können hier nachvollziehen, welche Anträge Sie bereits gestellt haben und den Status der jeweiligen Anträge einsehen.
                    </div>
                </div>
            </div>
    </div>      
</div>

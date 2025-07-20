<?php

namespace App\Livewire\Tools;

use Livewire\Component;
use App\Models\WebPage;

class SearchModal extends Component
{
    public $openSearchMenu = false;
    public $query = '';
    public $searchType = 'all';


    public $resultsInfos = [];

    protected $listeners = [
        'navhide' => 'navhide',
    ];  

    public function navhide()
    {
        $this->openSearchMenu = false;
    }

    public function updatedOpenSearchMenu()
    {
        if ($this->openSearchMenu) {
            $this->dispatch('search-modal-opened');
        }
    }

    public function updatedQuery()
    {
        if (strlen($this->query) < 2) {
            $this->resultsInsurances = [];
            $this->resultsTypes = [];
            $this->resultsInfos = [];
            return;
        }
    
        switch ($this->searchType) {
            case 'types':
                $this->resultsTypes = [];
                $this->resultsInsurances = [];
                $this->resultsInfos = [];
                break;
    
            case 'infos':
                $this->resultsInfos = WebPage::where('is_fixed', true)
                    ->where(function ($query) {
                        $query->where('title', 'like', '%' . $this->query . '%')
                            ->orWhereHas('project', function ($q) {
                                $q->where('cleaned_html', 'like', '%' . $this->query . '%');
                            });
                    })
                    ->with('project')
                    ->limit(10)
                    ->get();

                $this->resultsInsurances = [];
                $this->resultsTypes = [];
                break;
    
            case 'insurances':
                $this->resultsInsurances = [];
                $this->resultsTypes = [];
                $this->resultsInfos = [];
                break;
    
            default:
                $this->resultsInsurances = [];
    
                //$this->resultsTypes = InsuranceType::where(function ($query) {
                //    $query->where('name', 'like', '%' . $this->query . '%')
                //          ->orWhere('description', 'like', '%' . $this->query . '%');
                //})->limit(5)->get();
                $this->resultsTypes = [];
                $this->resultsInfos = WebPage::where('is_fixed', true)
                ->where(function ($query) {
                    $query->where('title', 'like', '%' . $this->query . '%')
                        ->orWhereHas('project', function ($q) {
                            $q->where('html', 'like', '%' . $this->query . '%');
                        });
                })
                ->limit(5)
                ->get();
                break;
        }
    }

    public function selectSearchType($searchType)
    {
        $this->searchType = $searchType;
        $this->updatedQuery();
    }
    

    public function selectResult($id, $type)
    {
        switch ($type) {
            case 'types':
                return redirect()->route('insurance-types.show', ['id' => $id]);
    
            case 'infos':
                $webPage = WebPage::findOrFail($id);
                return redirect('/' . $webPage->slug);
    
            case 'insurances':
            default:
                $insurance = Insurance::findOrFail($id);
                return redirect()->route('insurance.show-insurance', $insurance);
        }
    }
    

    public function render()
    {
        return view('livewire.tools.search-modal');
    }
}

<?php

namespace App\Livewire\Tutor;

use Livewire\Component;
use App\Models\Course;
use Illuminate\Support\Facades\Auth;

class CourseList extends Component
{
    public $search = '';
    public $courses = [];

    public function mount()
    {
        $this->loadCourses();
    }

    public function updatedSearch()
    {
        $this->loadCourses();
    }

    public function loadCourses()
    {
        $this->courses = Course::where('tutor_id', Auth::id())
            ->where('title', 'like', '%' . $this->search . '%')
            ->withCount('days') // wenn du course_days hast
            ->orderBy('start_time', 'desc')
            ->get();
    }

    public function render()
    {
        return view('livewire.tutor.course-list')->layout("layouts.app-tutor");
    }
}

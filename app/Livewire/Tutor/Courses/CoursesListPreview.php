<?php

namespace App\Livewire\Tutor\Courses;

use Livewire\Component;
use Illuminate\Support\Facades\Auth;
use App\Models\Course;

class CoursesListPreview extends Component
{
        public $courses;

    public function mount()
    {
        $this->courses = auth()->user()
            ->courses() // Beziehung: User hat viele Kurse (über tutor_id)
            ->orderBy('end_time')
            ->take(4)
            ->get();
    }

    public function render()
    {
        return view('livewire.tutor.courses.courses-list-preview');
    }
}

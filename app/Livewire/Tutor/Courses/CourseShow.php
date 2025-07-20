<?php

namespace App\Livewire\Tutor\Courses;

use App\Models\Course;
use Livewire\Component;

class CourseShow extends Component
{
    public Course $course;

    public function mount($courseId)
    {
        $this->course = Course::with(['tutor', 'participants', 'days'])->findOrFail($courseId);
    }

    public function render()
    {
        return view('livewire.tutor.courses.course-show')->layout("layouts.app-tutor");
    }
}


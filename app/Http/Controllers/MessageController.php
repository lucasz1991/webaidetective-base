<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;

class MessageController extends Controller
{

        /**
         * Display a listing of the messages.
         */
        public function index()
        {
            $messages = Message::with(['sender', 'recipient'])->get();
            return view('messages.index', compact('messages'));
        }
    
        /**
         * Show the form for creating a new message.
         */
        public function create()
        {
            $users = User::all();
            return view('messages.create', compact('users'));
        }
    
        /**
         * Store a newly created message in storage.
         */
        public function store(Request $request)
        {
            $request->validate([
                'subject' => 'required|string|max:255',
                'message' => 'required|string',
                'from_user' => 'required|exists:users,id',
                'to_user' => 'required|exists:users,id',
                'status' => 'required|string',
            ]);
    
            Message::create($request->all());
    
            return redirect()->route('messages.index')->with('success', 'Message sent successfully.');
        }
    
        /**
         * Display the specified message.
         */
        public function show(Message $message)
        {
            return view('messages.show', compact('message'));
        }
    
        /**
         * Show the form for editing the specified message.
         */
        public function edit(Message $message)
        {
            $users = User::all();
            return view('messages.edit', compact('message', 'users'));
        }
    
        /**
         * Update the specified message in storage.
         */
        public function update(Request $request, Message $message)
        {
            $request->validate([
                'subject' => 'required|string|max:255',
                'message' => 'required|string',
                'from_user' => 'required|exists:users,id',
                'to_user' => 'required|exists:users,id',
                'status' => 'required|string',
            ]);
    
            $message->update($request->all());
    
            return redirect()->route('messages.index')->with('success', 'Message updated successfully.');
        }
    
        /**
         * Remove the specified message from storage.
         */
        public function destroy(Message $message)
        {
            $message->delete();
    
            return redirect()->route('messages.index')->with('success', 'Message deleted successfully.');
        }
    }


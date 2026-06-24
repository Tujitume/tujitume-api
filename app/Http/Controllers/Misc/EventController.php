<?php

namespace App\Http\Controllers\Misc;

use App\Http\Controllers\Controller;
use App\Models\Misc\Event;
use App\Service\Misc\ErrorLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;

class EventController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try {
            $user = Auth::user();
            $events = $user->events;

            return response()->json([
                'events' => $events,
            ], 200);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            // Validate the request
            $validated = $request->validate([
                'event_name'     => 'required|string|max:255',
                'event_type'     => 'required|string|max:255',
                'sector'         => 'required|string|max:255',
                'start_date'     => 'required|date',
                'end_date'       => 'required|date|after:start_date',
                'timezone'       => 'required|string|max:255',
                'location_type'  => ['required', Rule::in(['in-person', 'virtual'])],

                // in-person
                'country' => 'nullable|required_if:location_type,in-person|string|max:255',
                'city'    => 'nullable|required_if:location_type,in-person|string|max:255',
                'venue'   => 'nullable|required_if:location_type,in-person|string|max:255',
                'address' => 'nullable|string|max:500',

                // virtual
                'virtual_url'       => 'required_if:location_type,virtual|url|nullable',

                'description'       => 'required|string',
                'cost_type'         => ['required', Rule::in(['paid', 'free'])],
                'currency'          => 'required_if:cost_type,paid|string|max:10|nullable',
                'price'             => 'required_if:cost_type,paid|numeric|nullable',
                'ticket_link'       => 'required_if:cost_type,paid|url',
                'cover_image'       => 'required|image|max:2048', // max 2MB
                'brochure'          => 'nullable|file|mimes:pdf|max:5120', // max 5MB
                'tags'              => 'nullable|array',
                //'tags.*'            => 'string|max:50',
            ]);

            // Convert tags array to JSON
            $validated['tags'] = $validated['tags'] ?? [];
            $validated['user_id'] = Auth::id();

            // Save the event
            $event = Event::create($validated);

            $eventId = $event->id;
            $eventFolder = public_path("files/events/{$eventId}");

            if (!file_exists($eventFolder)) {
                mkdir($eventFolder, 0755, true); // recursive folder creation
            }

            // Handle cover image
            if ($request->hasFile('cover_image')) {
                $coverImage = $request->file('cover_image');
                $coverImageName = 'cover_' . time() . '.' . $coverImage->getClientOriginalExtension();
                $coverImage->move($eventFolder, $coverImageName);
                $event->cover_image = "files/events/{$eventId}/{$coverImageName}";
            }

            // Handle brochure
            if ($request->hasFile('brochure')) {
                $brochure = $request->file('brochure');
                $brochureName = 'brochure_' . time() . '.' . $brochure->getClientOriginalExtension();
                $brochure->move($eventFolder, $brochureName);
                $event->brochure = "files/events/{$eventId}/{$brochureName}";
            }

            $event->save();

            return response()->json([
                'message' => 'Event created successfully',
                'event'   => $event,
            ], 200);

        } catch (\Exception $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }
    /**
     * Display the specified resource.
     */
    public function show(int $id)
    {
        try {
            $event = Event::find($id);
            return response()->json([
                'event' => $event,
            ], 200);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }

    }

    public function browse()
    {
        try {
            $events = Event::all();
            return response()->json([
                'events' => $events,
                'message' => 'Events retrieved successfully.',
            ], 200);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }

    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request)
    {
        try {
            //return $request->file('cover_image');
            // Fetch the event
            $event = Event::findOrFail($request->id);

            // Validate request
            $validated = $request->validate([
                'event_name'     => 'required|string|max:255',
                'event_type'     => 'required|string|max:255',
                'sector'         => 'required|string|max:255',
                'cover_image'       => 'image|max:2048', // max 2MB
                'brochure'          => 'nullable|file|mimes:pdf|max:5120', // max 5MB
                'start_date'     => 'required|date',
                'end_date'       => 'required|date|after:start_date',
                'timezone'       => 'required|string|max:255',
                'location_type'  => ['required', Rule::in(['in-person', 'virtual'])],

                // in-person
                'country' => 'nullable|required_if:location_type,in-person|string|max:255',
                'city'    => 'nullable|required_if:location_type,in-person|string|max:255',
                'venue'   => 'nullable|required_if:location_type,in-person|string|max:255',
                'address' => 'nullable|string|max:500',

                // virtual
                'virtual_url'       => 'required_if:location_type,virtual|url|nullable',

                'description'       => 'required|string',
                'cost_type'         => ['required', Rule::in(['paid', 'free'])],
                'currency'          => 'required_if:cost_type,paid|string|max:10|nullable',
                'price'             => 'required_if:cost_type,paid|numeric|nullable',
                'ticket_link'       => 'nullable|url',
                'tags'              => 'nullable|array',
                //'tags.*'            => 'string|max:50',
            ]);

            // Convert tags array to JSON
            $validated['tags'] = $validated['tags'] ?? [];
            $eventFolder = public_path("files/events/{$event->id}");

            if (!file_exists($eventFolder)) {
                mkdir($eventFolder, 0755, true);
            }

            // Handle cover image replacement
            if ($request->hasFile('cover_image')) {
                // delete old one if exists
                if ($event->cover_image && file_exists(public_path($event->cover_image))) {
                    unlink(public_path($event->cover_image));
                }

                $coverImage = $request->file('cover_image');
                $coverImageName = 'cover_' . time() . '.' . $coverImage->getClientOriginalExtension();
                $coverImage->move($eventFolder, $coverImageName);
                $validated['cover_image'] = "files/events/{$event->id}/{$coverImageName}";
            }

            // Handle brochure replacement
            if ($request->hasFile('brochure')) {
                if ($event->brochure && file_exists(public_path($event->brochure))) {
                    unlink(public_path($event->brochure));
                }

                $brochure = $request->file('brochure');
                $brochureName = 'brochure_' . time() . '.' . $brochure->getClientOriginalExtension();
                $brochure->move($eventFolder, $brochureName);
                $validated['brochure'] = "files/events/{$event->id}/{$brochureName}";
            }

            // Update event data
            $event->update($validated);

            return response()->json([
                'message' => 'Event updated successfully',
                'event' => $event,
            ], 200);

        } catch (\Exception $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id)
    {
        $user = Auth::user();
        $event = Event::find($id);

        if (!$event) {
            return response()->json(['message' => 'Event not found'], 404);
        }

        // Optional: ensure the user owns this event
        if ($event->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Delete files/folder if exists
        $eventFolder = public_path("files/events/{$event->id}");

        if (File::exists($eventFolder)) {
            File::deleteDirectory($eventFolder);
        }

        // Delete record
        $event->delete();

        return response()->json(['message' => 'Event deleted successfully'], 200);
    }

    public function activate(Request $request)
    {
        try{
            $request->validate([
                'id' => 'required|integer|exists:events,id',
            ]);
            Event::where('id', $request->id)->update([ 'active' => 1 ]);
            return response()->json(['message' => 'Success'], 200);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }
    public function deactivate(Request $request)
    {
        try{
            $request->validate([
                'id' => 'required|integer|exists:events,id',
            ]);
            Event::where('id', $request->id)->update([ 'active' => 0 ]);
            return response()->json(['message' => 'Success'], 200);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }


//Class
}

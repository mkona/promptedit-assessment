<?php

namespace App\Http\Controllers;

use App\Billing\CostCalculator;
use App\Billing\Credits;
use App\Enums\GenerationStatus;
use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\PixelforgeClient;
use App\Support\Nanodollars;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function __construct(
        private readonly PixelforgeClient $pixelforge,
        private readonly CostCalculator $calculator,
        private readonly Credits $credits,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $pricing = $this->pixelforge->pricing();

        $generations = $user->generations()->latest('id')->limit(50)->get();

        return Inertia::render('generations/Index', [
            'generations' => GenerationResource::collection($generations)->resolve(),
            'hasPending' => $generations->contains(fn (Generation $g) => ! $g->status->isFinal()),
            'balance' => Nanodollars::toDecimal($this->credits->balance($user)),
            'sizes' => $pricing->sizes(),
            'qualities' => $pricing->qualities(),
            'creditPackages' => CreditController::PACKAGES,
        ]);
    }

    public function estimate(Request $request): JsonResponse
    {
        $pricing = $this->pixelforge->pricing();

        $validated = $request->validate([
            'characters' => ['required', 'integer', 'min:0', 'max:4000'],
            'size' => ['required', Rule::in($pricing->sizes())],
            'quality' => ['required', Rule::in($pricing->qualities())],
            'references' => ['required', 'integer', 'min:0', 'max:4'],
        ]);

        $estimate = $this->calculator->estimate(
            $pricing,
            (int) $validated['characters'],
            $validated['size'],
            $validated['quality'],
            (int) $validated['references'],
        );

        return response()->json($estimate->toArray());
    }

    public function store(StoreGenerationRequest $request): RedirectResponse
    {
        $input = $request->validated();
        $references = array_values($input['reference_images'] ?? []);

        $estimate = $this->calculator->estimate(
            $this->pixelforge->pricing(),
            mb_strlen($input['prompt']),
            $input['size'],
            $input['quality'],
            count($references),
        );

        $generation = DB::transaction(function () use ($request, $input, $references, $estimate) {
            // Lock the user so two requests at once can't both spend the same credits.
            User::query()->lockForUpdate()->findOrFail($request->user()->id);

            if ($this->credits->balance($request->user()) < $estimate->maximumPrice) {
                throw ValidationException::withMessages([
                    'prompt' => 'Not enough credits. This generation can cost up to $'
                        .Nanodollars::toDecimal($estimate->maximumPrice).'. Buy credits to continue.',
                ]);
            }

            $generation = Generation::create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $request->user()->id,
                'status' => GenerationStatus::Submitting,
                'prompt' => $input['prompt'],
                'size' => $input['size'],
                'quality' => $input['quality'],
                'reference_images' => $references,
                'estimated_cost' => $estimate->expectedCost,
                'estimated_price' => $estimate->expectedPrice,
                'max_price' => $estimate->maximumPrice,
            ]);

            $this->credits->hold($generation);

            return $generation;
        });

        SubmitGeneration::dispatch($generation);

        return back();
    }
}

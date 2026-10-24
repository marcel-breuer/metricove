<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PreviewRecipeImportRequest;
use App\Http\Requests\StoreRecipeRequest;
use App\Http\Requests\UpdateRecipeRequest;
use App\Models\User;
use App\Services\Recipes\RecipeImportService;
use App\Services\Recipes\RecipeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RecipesController extends Controller
{
    public function importPreview(PreviewRecipeImportRequest $request, RecipeImportService $imports): JsonResponse
    {
        $actor = $this->actor($request);
        $data = $request->validated();
        $preview = $imports->preview(
            $actor,
            $data['visibility'],
            $data['source_url'] ?? null,
            $request->file('file'),
        );

        return response()->json($preview);
    }

    public function index(Request $request, RecipeService $recipes): JsonResponse
    {
        return response()->json(['recipes' => $recipes->index($this->actor($request))]);
    }

    public function show(Request $request, int $recipe, RecipeService $recipes): JsonResponse
    {
        return response()->json(['recipe' => $recipes->show($this->actor($request), $recipe)]);
    }

    public function store(StoreRecipeRequest $request, RecipeService $recipes): JsonResponse
    {
        return response()->json([
            'message' => 'Recipe created.',
            'recipe' => $recipes->create($this->actor($request), $request->validated()),
        ], 201);
    }

    public function update(UpdateRecipeRequest $request, int $recipe, RecipeService $recipes): JsonResponse
    {
        return response()->json([
            'message' => 'Recipe updated.',
            'recipe' => $recipes->update($this->actor($request), $recipe, $request->validated()),
        ]);
    }

    public function destroy(Request $request, int $recipe, RecipeService $recipes): JsonResponse
    {
        $recipes->delete($this->actor($request), $recipe);

        return response()->json(['message' => 'Recipe deleted.']);
    }

    public function favorite(Request $request, int $recipe, RecipeService $recipes): JsonResponse
    {
        return response()->json([
            'message' => 'Recipe added to favorites.',
            'recipe' => $recipes->favorite($this->actor($request), $recipe),
        ]);
    }

    public function unfavorite(Request $request, int $recipe, RecipeService $recipes): JsonResponse
    {
        $recipes->unfavorite($this->actor($request), $recipe);

        return response()->json(['message' => 'Recipe removed from favorites.']);
    }

    public function photo(Request $request, int $recipe, RecipeService $recipes): BinaryFileResponse
    {
        $response = response()->file($recipes->photo($this->actor($request), $recipe), [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function actor(Request $request): User
    {
        $actor = $request->user('web');

        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}

<?php

  namespace App\Services\Models;

  use App\Item as Model;
  use App\Item;
  use App\Services\IMDB;
  use App\Services\Storage;
  use App\Services\TMDB;
  use App\Jobs\UpdateItem;
  use App\Setting;
  use Illuminate\Support\Facades\DB;
  use Symfony\Component\HttpFoundation\Response;

  class ItemService {
    const FLOX_FIELD_TITLE = 'title';
    const FLOX_FIELD_RATING = 'rating';
    const FLOX_FIELD_RELEASED = 'released';
    const FLOX_FIELD_TMDB_RATING = 'tmdb_rating';
    const FLOX_FIELD_IMDB_RATING = 'imdb_rating';
    const FLOX_FIELD_IS_HISTORIC = 'is_historic';
    const FLOX_FIELD_LAST_SEEN_AT = 'last_seen_at';


    private $model;
    private $tmdb;
    private $storage;
    private $alternativeTitleService;
    private $episodeService;
    private $imdb;
    private $setting;
    private $genreService;

    /**
     * @param Model $model
     * @param TMDB $tmdb
     * @param Storage $storage
     * @param AlternativeTitleService $alternativeTitleService
     * @param EpisodeService $episodeService
     * @param GenreService $genreService
     * @param IMDB $imdb
     * @param Setting $setting
     */
    public function __construct(
      Model $model,
      TMDB $tmdb,
      Storage $storage,
      AlternativeTitleService $alternativeTitleService,
      EpisodeService $episodeService,
      GenreService $genreService,
      IMDB $imdb,
      Setting $setting
    ){
      $this->model = $model;
      $this->tmdb = $tmdb;
      $this->storage = $storage;
      $this->alternativeTitleService = $alternativeTitleService;
      $this->episodeService = $episodeService;
      $this->imdb = $imdb;
      $this->setting = $setting;
      $this->genreService = $genreService;
    }

    /**
     * @param $data
     * @return Model
     */
    public function create($data)
    {
      DB::beginTransaction();

      $data = $this->makeDataComplete($data);

      $item = $this->model->store($data);

      $this->episodeService->create($item);
      $this->genreService->sync($item, $data['genre_ids'] ?? []);
      $this->alternativeTitleService->create($item);

      $this->storage->downloadImages($item->poster, $item->backdrop);

      DB::commit();

      return $item->fresh();
    }

    /**
     * Search against TMDb and IMDb for more informations.
     * We don't need to get more informations if we add the item from the subpage.
     *
     * @param $data
     * @return array
     */
    public function makeDataComplete($data)
    {
      if( ! isset($data['imdb_id'])) {
        $details = $this->tmdb->details($data['tmdb_id'], $data['media_type']);
        $title = $details->name ?? $details->title;

        $data['imdb_id'] = $data['imdb_id'] ?? $this->parseImdbId($details);
        $data['youtube_key'] = $data['youtube_key'] ?? $this->parseYoutubeKey($details, $data['media_type']);
        $data['overview'] = $data['overview'] ?? $details->overview;
        $data['tmdb_rating'] = $data['tmdb_rating'] ?? $details->vote_average;
        $data['backdrop'] = $data['backdrop'] ?? $details->backdrop_path;
        $data['slug'] = $data['slug'] ?? getSlug($title);
        $data['homepage'] = $data['homepage'] ?? $details->homepage;
      }

      $data[self::FLOX_FIELD_IMDB_RATING] = $this->parseImdbRating($data);

      return $data;
    }

    /**
     * Refresh informations for all items.
     */
    public function refreshAll()
    {
      logInfo("Refresh all items");
      increaseTimeLimit();

      $this->genreService->updateGenreLists();

      $this->model->orderBy('refreshed_at')->get()->each(function($item) {
        UpdateItem::dispatch($item->id);
      });
    }

    /**
     * Refresh informations for an item.
     * Like ratings, new episodes, new poster and backdrop images.
     *
     * @param $itemId
     *
     * @return Response|false
     */
    public function refresh($itemId)
    {
      logInfo("Start refresh for item [$itemId]");

      $item = $this->model->findOrFail($itemId);

      $details = $this->tmdb->details($item->tmdb_id, $item->media_type);

      $title = $details->name ?? ($details->title ?? null);

      // If TMDb didn't find anything then title will be not set => don't update
      if( ! $title) {
        return false;
      }

      logInfo("Refresh", [$title]);

      $this->storage->removeImages($item->poster, $item->backdrop);

      $imdbId = $item->imdb_id ?? $this->parseImdbId($details);

      $item->update([
        'imdb_id' => $imdbId,
        'youtube_key' => $this->parseYoutubeKey($details, $item->media_type),
        'overview' => $details->overview,
        'tmdb_rating' => $details->vote_average,
        'imdb_rating' => $this->parseImdbRating(['imdb_id' => $imdbId]),
        'backdrop' => $details->backdrop_path,
        'poster' => $details->poster_path,
        'slug' => getSlug($title),
        'title' => $title,
        'homepage' => $details->homepage ?? null,
        'original_title' => $details->original_name ?? $details->original_title,
      ]);

      $this->episodeService->create($item);
      $this->alternativeTitleService->create($item);

      $this->genreService->sync(
        $item,
        collect($details->genres)->pluck('id')->all()
      );

      $this->storage->downloadImages($item->poster, $item->backdrop);
    }

    /**
     * If the user clicks to fast on adding item,
     * we need to re-fetch the rating from IMDb.
     *
     * @param $data
     *
     * @return float|null
     */
    private function parseImdbRating($data)
    {
      if( ! isset($data[self::FLOX_FIELD_IMDB_RATING])) {
        $imdbId = $data['imdb_id'];

        if($imdbId) {
          return $this->imdb->parseRating($imdbId);
        }

        return null;
      }

      // Otherwise we already have the rating saved.
      return $data[self::FLOX_FIELD_IMDB_RATING];
    }

    /**
     * TV shows needs an extra append for external ids.
     *
     * @param $details
     * @return mixed
     */
    public function parseImdbId($details)
    {
      return $details->external_ids->imdb_id ?? ($details->imdb_id ?? null);
    }

    /**
     * Get the key for the youtube trailer video. Fallback with english trailer.
     *
     * @param $details
     * @param $mediaType
     * @return string|null
     */
    public function parseYoutubeKey($details, $mediaType)
    {
      if(isset($details->videos->results[0])) {
        return $details->videos->results[0]->key;
      }

      // Try to fetch details again with english language as fallback.
      $videos = $this->tmdb->videos($details->id, $mediaType, 'en');

      return $videos->results[0]->key ?? null;
    }

    /**
     * @param $data
     * @param $mediaType
     * @return Model
     */
    public function createEmpty($data, $mediaType)
    {
      $mediaType = mediaType($mediaType);

      $data = [
        'name' => getFileName($data),
        'src' => $data->changed->src ?? $data->src,
        'subtitles' => $data->changed->subtitles ?? $data->subtitles,
      ];

      return $this->model->storeEmpty($data, $mediaType);
    }

    /**
     * Delete movie or tv show (with episodes and alternative titles).
     * Also remove the poster image file.
     *
     * @param $itemId
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function remove($itemId)
    {
      $item = $this->model->find($itemId);

      if( ! $item) {
        return response('Not Found', Response::HTTP_NOT_FOUND);
      }

      $tmdbId = $item->tmdb_id;

      $item->delete();

      // Delete all related episodes, alternative titles and images.
      $this->episodeService->remove($tmdbId);
      $this->alternativeTitleService->remove($tmdbId);
      $this->storage->removeImages($item->poster, $item->backdrop);
    }

      /**
       * Delete movie or tv show (with episodes and alternative titles).
       * Also remove the poster image file.
       *
       * @param $itemId
       * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
       */
      public function toggleHistoric($itemId)
      {
          $item = $this->model->find($itemId);

          if( ! $item) {
              return response('Not Found', Response::HTTP_NOT_FOUND);
          }

          if ($item->is_historic === 0) {
              $item->update([
                  'is_historic' => 1
              ]);
          } else {
              // setting "non historic" from "historic" updates the
              // last_seen timestamp, so the Item is considered the
              // newest item
              $item->update([
                  'is_historic' => 0,
                  'last_seen_at' => now()
              ]);
          }
      }


    /**
     * Return all items with pagination.
     *
     * @param $type
     * @param $orderBy
     * @param $sortDirection
     * @return mixed
     */
    public function getWithPagination($type, $orderBy, $sortDirection)
    {
      $filter = $this->getSortFilter($orderBy);
      $items = $this->model->with('latestEpisode')->withCount('episodesWithSrc');

      if ($filter === self::FLOX_FIELD_IS_HISTORIC) {
          /*
           * Adding a computed column to sort by. The resultset should list all non historic entries
           * first in the order of last_seen descending, than all historic in alphabetical order
           */
          $items->addSelect(DB::raw('CONCAT(is_historic, IF(is_historic = 0, FROM_UNIXTIME(NOW() - '.self::FLOX_FIELD_LAST_SEEN_AT.'), title)) AS historySortKey'));
          $items->orderBy('historySortKey', 'asc');
      } else {
          $items->orderBy($filter, $sortDirection);
      }

      if($type == 'watchlist') {
        $items->where('watchlist', true);
      } elseif( ! $this->setting->first()->show_watchlist_everywhere) {
        $items->where('watchlist', false);
      }

      if($type == 'tv' || $type == 'movie') {
        $items->where('media_type', $type);
      }

      return $items->simplePaginate(config('app.LOADING_ITEMS'));
    }

    /**
     * Update rating.
     *
     * @param $itemId
     * @param $rating
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function changeRating($itemId, $rating)
    {
      $item = $this->model->find($itemId);

      if( ! $item) {
        return response('Not Found', Response::HTTP_NOT_FOUND);
      }

      // Update the parent relation only if we change rating from neutral.
      if($item->rating == 0) {
        $this->model->updateLastSeenAt($item->tmdb_id);
      }

      $item->update([
        'rating' => $rating,
        'watchlist' => false,
      ]);
    }

    /**
     * Search for all items by title in our database.
     *
     * @param $title
     * @return mixed
     */
    public function search($title)
    {
      return $this->model->findByTitle($title)->with('latestEpisode')->withCount('episodesWithSrc')->get();
    }

    /**
     * Create a new item from import.
     *
     * @param $item
     */
    public function import($item)
    {
      logInfo("Importing", [$item->title]);

      // Fallback if export was from an older version of flox (<= 1.2.2).
      if( ! isset($item->last_seen_at)) {
        $item->last_seen_at = Carbon::createFromTimestamp($item->created_at);
      }

      // New versions of flox has no genre field anymore.
      if(isset($item->genre)) {
        unset($item->genre);
      }

      // For empty items (from file-parser) we don't need access to details.
      if($item->tmdb_id) {
        $item = $this->makeDataComplete((array) $item);
        $this->storage->downloadImages($item['poster'], $item['backdrop']);
      }

      $item = collect($item)->except('id')->toArray();

      Item::create($item);
    }

    /**
     * See if we can find a item by title, fp_name, tmdb_id or src in our database.
     *
     * If we search from file-parser, we also need to filter the results by media_type.
     * If we have e.g. 'Avatar' as tv show, we don't want results like the 'Avatar' movie.
     *
     * @param $type
     * @param $value
     * @param $mediaType
     * @return mixed
     */
    public function findBy($type, $value, $mediaType = null)
    {
      if($mediaType) {
        $mediaType = mediaType($mediaType);
      }

      switch($type) {
        case 'title':
          return $this->model->findByTitle($value, $mediaType)->first();
        case 'title_strict':
          return $this->model->findByTitleStrict($value, $mediaType)->first();
        case 'fp_name':
          return $this->model->findByFPName($value, $mediaType)->first();
        case 'tmdb_id':
          return $this->model->findByTmdbId($value)->with('latestEpisode')->first();
        case 'src':
          return $this->model->findBySrc($value)->first();
      }

      return null;
    }

    /**
     * Get the correct name from the table for sort filter.
     *
     * @param $orderBy
     * @return string
     */
    private function getSortFilter($orderBy)
    {
      switch($orderBy) {
        case 'own rating':
          return self::FLOX_FIELD_RATING;
        case 'title':
          return self::FLOX_FIELD_TITLE;
        case 'release':
          return self::FLOX_FIELD_RELEASED;
        case 'tmdb rating':
          return self::FLOX_FIELD_TMDB_RATING;
        case 'imdb rating':
          return self::FLOX_FIELD_IMDB_RATING;
        case 'last seen with history':
          return self::FLOX_FIELD_IS_HISTORIC;
        default:
        case 'last seen':
          return self::FLOX_FIELD_LAST_SEEN_AT;
      }
    }
  }

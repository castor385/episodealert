<?php namespace EA\controllers;

use BaseController;
use Response;
use EA\models\Series;
use EA\models\Episode;
use EA\models\Following;
use EA\models\User;
use EA\models\Seen;
use Auth;
use Log;
use Input;
use DB;
use DateTime;
use DateInterval;
use App;

class SeriesController extends BaseController
{
    public function getSeries($uniqueName)
    {
        $series = Series::where('unique_name', $uniqueName)->first();
        // $series->following = $series->isFollowing();

        if (Auth::user()) {
            $series->last_seen_season = self::getLastSeenSeason($series->id, Auth::user()->id);
        } else {
            $series->last_seen_season = 1;
        }

        return Response::json($series);
    }


    /*
     * Get last seen season episode. Returns the season of which the user last saw an episode
     */
    private function getLastSeenSeason($series_id, $user_id)
    {
        $lastSeenSeason = Seen::where('user_id', $user_id)
            ->where('series_id', '=', $series_id)
            ->orderBy('season', 'desc')
            ->first();
        if (count($lastSeenSeason) > 0) {
            return $lastSeenSeason->season;
        } else {
            //default return season 1
            return 1;
        }

    }

    public function getByGenre($genre, $skip = 0)
    {
        return Response::json(
            Series::where('category', 'like', '%' . $genre . '%')
                ->orderBy('updated_at', 'desc')
                ->skip($skip)
                ->take(12)
                ->get()
        );
    }

    public function top()
    {
        // TODO: Make this select top (followed or trending?) series, instead of the first 5
        // use trend in series, is faster.
        $topSeries =
            Series::join(
                DB::raw(
                    '(select series_id, count(*) as count from following
                    group by series_id
                    order by count desc
                    limit 50) bil'
                ),
                function ($join) {
                    $join->on('series.id', '=', 'bil.series_id');
                }
            )
            ->where('fanart_image_converted', '=', 1)
            ->get()
            ->random(5);

        $array = array();
        if (count($topSeries) > 0) {
            $array = array_values($topSeries);
        }

        return Response::json($array);
    }

    /**
     * @return type
     */
    public function trending()
    {
        //most followed series in the past 15 days
        $date = new DateTime;
        $trendingDate = $date->sub(new DateInterval('P15D'))->format('Y-m-d H:i:s');

        return Response::json(
            Series::join('following', 'following.series_id', '=', 'series.id')
                ->where('following.created_at', '>', $trendingDate)
                ->groupBy('following.series_id')
                ->orderBy(DB::raw('count(following.id)'), 'desc')
                ->take(5)
                ->get()
        );
    }

    public function search($query)
    {
        return Response::json(
            Series::where('name', 'like', '%' . $query . '%')
                ->orderBy('trend', 'desc')
                ->take(200)
                ->get()
        );
    }

    public function getEpisodesBySeason($series_id, $season)
    {
        $episodes = Episode::where('series_id', $series_id)
            ->where('season', $season)
            ->orderBy('season', 'asc')
            ->orderBy('episode', 'asc')
            ->select(
                array(
                    'episode.*',
                    DB::raw(
                        sprintf(
                            "case when airdate < '%s' then 1 else 0 end as aired",
                            date('Y-m-d')
                        )
                    )
                )
            )
            ->get();

        return Response::json($episodes);
    }

    /*
	 * Get episodes of series by series->id
	 */
    public function getEpisodes($id)
    {
        if (Auth::user()) {
            $user_id = Auth::user()->id;

            $followingCheck = Following::where('series_id', $id)->where('user_id', $user_id)->count();

            if (!$followingCheck) {
                  return self::getEpisodesFromGivenSeason($id, 1);
            } else {
                return self::getEpisodesFromLatestSeason($id);
            }
        }
        return self::getEpisodesFromGivenSeason($id, 1);
    }

    /**
     * Get unseen episodes for series you follow, as well as upcoming episodes
     */
    public function getEpisodeGuide($daysInFuture)
    {
        $includeUpcoming = filter_var(Input::get('upcoming', 'true'), FILTER_VALIDATE_BOOLEAN);
        $includeUnseen = filter_var(Input::get('unseen', 'true'), FILTER_VALIDATE_BOOLEAN);

        $currentEpisodes = Episode::join('series', 'series.id', '=', 'episode.series_id')
            ->join('following', function ($join) {
                $join->on('following.series_id', '=', 'episode.series_id')
                    ->where('following.user_id', '=', Auth::user()->id);
            })
            ->where('episode.airdate', '>=', DB::raw('date_sub(curdate(), interval 3 day)'))
            ->where('episode.airdate', '<=', DB::raw('date_add(curdate(), interval ' . $daysInFuture .' day)'))
            ->orderBy('episode.airdate', 'asc')
            ->orderBy('episode.season', 'asc')
            ->orderBy('episode.episode', 'asc')
            ->get(
                array(
                    'episode.*',
                    'series.name as seriesName',
                    'series.unique_name',
                    DB::raw(
                        sprintf(
                            "case when airdate < '%s' then 1 else 0 end as aired",
                            date('Y-m-d')
                        )
                    )
                )
            );

        return Response::json($currentEpisodes);

/* OLD, SLOW, GUIDE THING
        $seriesFollowed = Series::join('following', 'following.series_id', '=', 'series.id')
            ->where('following.user_id', '=', auth::user()->id)
            ->orderBy('following.updated_at', 'desc')
            ->get(array('series.*'));

        foreach ($seriesFollowed as $key => $series) {
            // Fetch last three unseen episodes, if needed
            if (filter_var(Input::get('unseen', 'true'), FILTER_VALIDATE_BOOLEAN)) {
                $series->unseen = Episode::leftJoin('seen', function ($join) {
                        $join->on('seen.episode_id', '=', 'episode.id')
                            ->where('seen.user_id', '=', Auth::user()->id);
                })
                    ->where('episode.series_id', '=', $series->id)
                    ->whereNull('seen.id')
                    ->where('episode.airdate', '<', new DateTime('today'))
                    ->where('episode.season', '>', 0)
                    ->orderBy('episode.airdate', 'asc')
                    ->orderBy('episode.season', 'asc')
                    ->orderBy('episode.episode', 'asc')
                    ->take(3)
                    ->get(array('episode.*', DB::raw('1 as aired')));

                // And the total:
                // TODO: make sure we use the same count query everywhere, instead of having different counts.
                $series->unseen_total = Episode::leftJoin('seen', function ($join) {
                        $join->on('seen.episode_id', '=', 'episode.id')
                            ->where('seen.user_id', '=', Auth::user()->id);
                })
                    ->where('episode.series_id', '=', $series->id)
                    ->whereNull('seen.id')
                    ->where('episode.airdate', '>', '0000-00-00')
                    ->where('episode.airdate', '<', new DateTime('today'))
                    ->where('episode.season', '>', 0)
                    ->count();
            }

            if (filter_var(Input::get('upcoming', 'true'), FILTER_VALIDATE_BOOLEAN)) {
                // Fetch the first 3 unaired episodes
                $series->unaired = Episode::where('series_id', '=', $series->id)
                    ->where('airdate', '>=', new DateTime('today'))
                    ->where('episode.season', '>', 0)
                    ->orderBy('airdate', 'asc')
                    ->orderBy('episode.season', 'asc')
                    ->orderBy('episode.episode', 'asc')
                    ->take(3)
                    ->get();
            }

            if (($series->unaired == null || $series->unaired->count() == 0) &&
                ($series->unseen == null || $series->unseen->count() == 0)) {
                $seriesFollowed->forget($key);
            }
        };

        return Response::json($seriesFollowed);
        */
    }


    /*
     * Get episodes of series by series->id and from the latest season
     * Check if the serie is a following one, then find the season from the last seen 
     * episode and return all episodes from that season.
     * Else return episodes from season 1 by default
     */
    private function getEpisodesFromLatestSeason($id)
    {
        Log::info("inside getEpisodesFromLatestSeason method ".$id);
        $lastSeason = Episode::where('series_id', $id)->select('season')->orderBy('season', 'desc')->first()->season;
        return self::getEpisodesFromGivenSeason($id, $lastSeason);
    }

    private function getEpisodesFromGivenSeason($id, $season)
    {
        return Response::json(
            Episode::where('series_id', $id)
                ->where('season', $season)
                ->orderBy('episode', 'asc')
                ->get()
        );
    }

    /*
     * Seen code
     */

    public function setSeenEpisode(Episode $episode)
    {
        $mode = Input::get('mode', Seen::MODE_SINGLE); // which mode to use?

        $service = App::make('series');

        switch ($mode) {
            case Seen::MODE_SINGLE:
                $seen = $service->setSeenSingleEpisode($episode, Auth::user());
                return Response::json(array('seen' => $seen->lists('episode_id')));
            case Seen::MODE_UNTIL:
                $seen = $service->setSeenUntilEpisode($episode, Auth::user());
                return Response::json(array('seen' => $seen->lists('episode_id')));
            case Seen::MODE_SEASON:
                $seen = $service->setSeenSeason($episode, Auth::user());
                return Response::json(array('seen' => $seen->lists('episode_id')));
        }
        return Response::json(array('seen' => 'Unknown operation.'), 400);
    }

    public function unsetSeenEpisode(Episode $episode)
    {
        $mode = Input::get('mode', Seen::MODE_SINGLE); // which mode to use?
        $service = App::make('series');

        switch ($mode) {
            case Seen::MODE_SINGLE:
                $episodeIds = $service->setUnseenSingleEpisode($episode, Auth::user());
                return Response::json(array('unseen' => $episodeIds));
            case Seen::MODE_UNTIL:
                $episodeIds = $service->setUnseenUntilEpisode($episode, Auth::user());
                return Response::json(array('unseen' => $episodeIds));
            case Seen::MODE_SEASON:
                $episodeIds = $service->setUnseenSeason($episode, Auth::user());
                return Response::json(array('unseen' => $episodeIds));
        }
        return Response::json(array('unseen' => 'Unknown operation.'), 400);
    }

    /**
     * Get the total number of unseen episodes
     * path: /api/series/unseenamount
     */
    public function getUnseenEpisodes()
    {
        $totalEpisodes = DB::table('following')
            ->join('episode', 'episode.series_id', '=', 'following.series_id')
            ->where('following.user_id', '=', Auth::user()->id)
            ->where('episode.season', '>', 0)
            ->where('episode.airdate', '<', new DateTime('today'))
            ->count();
        $totalSeen = Seen::where('user_id', Auth::user()->id)->where('season', '>', 0)->count();

        return Response::json(array('unseenepisodes' => $totalEpisodes - $totalSeen));
    }

    /*
     *
     * Find the number of unseen episodes per season for a series
     * path: /api/series/unseenamountbyseason/{series_id}/{season_number}
     *
     */
    public function getUnseenEpisodesPerSeason($series_id, $season_number)
    {
        $user_id = Auth::user()->id;
        $totalAmountofEpisodes = Episode::where('series_id', $series_id)->where('season', $season_number)->count();
        $seenAmount = Seen::where('series_id', $series_id)
            ->where('user_id', $user_id)
            ->where('season', $season_number)
            ->count();

        $unseenAmountOfEpisodes = $totalAmountofEpisodes - $seenAmount;

        return Response::json(array('unseenepisodes' => $unseenAmountOfEpisodes, 'season' => $season_number));
    }

    /*
     * Get unseen episodes per series
     */

    public function getUnseenEpisodesPerSeries($series_id, $seasons_amount)
    {
        $seasonObject = array();
        $user_id = Auth::user()->id;

        for ($i=1; $i <= $seasons_amount; $i++) {
            $totalAmountofEpisodes = Episode::where('series_id', $series_id)
                ->where('season', $i)
                ->whereNotNull('airdate')
                ->where('airdate', '<', new DateTime('today'))
                ->count();

            $seenAmount = Seen::where('series_id', $series_id)
                ->where('user_id', $user_id)
                ->where('season', $i)
                ->count();

            $unseenAmountOfEpisodes = $totalAmountofEpisodes - $seenAmount;
            array_push($seasonObject, $unseenAmountOfEpisodes);
        }

        return Response::json($seasonObject);
    }


    /*
     * Get episodes for specific day for a user
     */

    public function getEpisodesForUserPerDate($date)
    {
        $episodes = Episode::join('series', 'series.id', '=', 'episode.series_id')
            ->join('following', function ($join) {
                $join->on('following.series_id', '=', 'episode.series_id')
                    ->where('following.user_id', '=', Auth::user()->id);
            })
        ->where('episode.airdate', '=', $date)
        ->orderBy('episode.airdate', 'asc')
        ->orderBy('episode.season', 'asc')
        ->orderBy('episode.episode', 'asc')
        ->get(
            array(
                'episode.*',
                'series.name as seriesName',
                'series.poster_image as seriesPoster',
                'series.unique_name as unique_name',
                DB::raw(
                    sprintf(
                        "case when airdate < '%s' then 1 else 0 end as aired",
                        date('Y-m-d')
                    )
                )
            )
        );

        return Response::json($episodes);
    }

    public function getSeriesPaginated()
    {
        $series = Series::orderBy('name', 'ASC')
            ->paginate(15);

        return Response::json($series);
    }
}

<?php declare(strict_types=1);

namespace Pehapkari\Youtube;

use Composer\CaBundle\CaBundle;
use GuzzleHttp\Client;
use Nette\Utils\DateTime;
use Nette\Utils\Json;
use Nette\Utils\Strings;
use Pehapkari\Youtube\Exception\YoutubeApiException;

final class YoutubeApi
{
    /**
     * @var string
     */
    private const PEHAPKARI_CHANNEL_ID = 'UCTBgI1P8xIn2pp2BBHbv5mg';

    /**
     * @var string
     * @see https://developers.google.com/youtube/v3/docs/playlistItems/list
     */
    private const ENDPOINT_VIDEOS_BY_PLAYLIST = 'https://www.googleapis.com/youtube/v3/playlistItems?part=snippet&playlistId=%s&maxResults=50';

    /**
     * @var string
     */
    private const ENPOINT_PLAYLISTS_BY_CHANNEL = 'https://www.googleapis.com/youtube/v3/playlists?part=snippet,contentDetails&channelId=%s&maxResults=50'; // 50 is allowed maximum

    /**
     * @var string
     */
    private $youtubeApiKey;

    /**
     * @var Client
     */
    private $client;

    public function __construct(string $youtubeApiKey)
    {
        $this->client = new Client([
            'verify' => CaBundle::getSystemCaRootBundlePath(),
        ]);

        $this->youtubeApiKey = $youtubeApiKey;
    }

    /**
     * @return mixed[]
     */
    public function getMeetupPlaylistsAndLivestreamPlaylist(): array
    {
        $playlists = [];

        $playlistsData = $this->getPlaylistsByChannel(self::PEHAPKARI_CHANNEL_ID);

        foreach ($playlistsData['items'] as $item) {
            $videosInPlaylistData = $this->getVideosByPlaylist($item);

            $videos = $this->createVideos($videosInPlaylistData);
            $playlist = $this->createPlaylist($item, $videos);

            $playlistTitle = $item['snippet']['title'];
            if ($playlistTitle === 'Twitch Livestream') {
                $playlists['livestream_playlist'] = $playlist;
            } elseif (Strings::match($playlistTitle, '#PHP( )?Prague#i')) {
                $playlists['php_prague_playlist'] = $playlist;
            } else {
                $playlists['meetup_playlists'][] = $playlist;
            }
        }

        return $playlists;
    }

    /**
     * @return mixed[]
     */
    private function getPlaylistsByChannel(string $channelId): array
    {
        return $this->getData(sprintf(self::ENPOINT_PLAYLISTS_BY_CHANNEL, $channelId));
    }

    /**
     * @param mixed[] $item
     * @return mixed[]
     */
    private function getVideosByPlaylist(array $item): array
    {
        $url = sprintf(self::ENDPOINT_VIDEOS_BY_PLAYLIST, $item['id']);

        return $this->getData($url);
    }

    /**
     * @param mixed[] $videoItems
     * @return mixed[]
     */
    private function createVideos(array $videoItems): array
    {
        $videos = [];

        foreach ($videoItems['items'] as $videoItem) {
            // skip private videos
            if ($videoItem['snippet']['title'] === 'Private video') {
                continue;
            }

            $video = [
                'title' => $videoItem['snippet']['title'],
                'description' => $videoItem['snippet']['description'],
                'video_id' => $videoItem['snippet']['resourceId']['videoId'],
            ];

            $thumbnails = $videoItem['snippet']['thumbnails'];
            if (isset($thumbnails['standard'])) {
                $video['thumbnail'] = $thumbnails['standard']['url'];
            } elseif (isset($thumbnails['high'])) {
                $video['thumbnail'] = $thumbnails['high']['url'];
            }

            $videos[] = $video;
        }

        return $videos;
    }

    /**
     * @param mixed[] $item
     * @param mixed[] $videos
     * @return mixed[]
     */
    private function createPlaylist(array $item, array $videos): array
    {
        return [
            'title' => $item['snippet']['title'],
            'published_at' => DateTime::from($item['snippet']['publishedAt']),
            'videos' => $videos,
        ];
    }

    /**
     * @return mixed[]
     */
    private function getData(string $url): array
    {
        $link = $url . '&key=' . $this->youtubeApiKey;

        $response = $this->client->request('GET', $link);
        if ($response->getStatusCode() !== 200) {
            throw new YoutubeApiException(sprintf('Unable load data for "%s"', $url));
        }

        return Json::decode($response->getBody()->getContents(), Json::FORCE_ARRAY);
    }
}

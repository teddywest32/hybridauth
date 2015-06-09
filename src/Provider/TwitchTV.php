<?php
/*!
* HybridAuth
* http://hybridauth.github.io | http://github.com/hybridauth/hybridauth
* (c) 2015 HybridAuth authors | http://hybridauth.github.io/license.html
*/

namespace Hybridauth\Provider;

use Hybridauth\Adapter\OAuth2;
use Hybridauth\Exception\UnexpectedValueException;
use Hybridauth\Data;
use Hybridauth\User;

/**
 *
 */
class TwitchTV  extends OAuth2
{
    /**
    * {@inheritdoc}
    */
    protected $scope = 'user_read channel_read';

    /**
    * {@inheritdoc}
    */
    protected $apiBaseUrl = 'https://api.twitch.tv/kraken/';

    /**
    * {@inheritdoc}
    */
    protected $authorizeUrl = 'https://api.twitch.tv/kraken/oauth2/authorize';

    /**
    * {@inheritdoc}
    */
    protected $accessTokenUrl = 'https://api.twitch.tv/kraken/oauth2/token';

    /**
    * {@inheritdoc}
    */
    protected $accessTokenName = 'oauth_token';

    /**
    * {@inheritdoc}
    */
    public function getUserProfile()
    {
        $response = $this->apiRequest('user');

        $data = new Data\Collection($response);

        if (! $data->exists('id')) {
            throw new UnexpectedValueException('Provider API returned an unexpected response.');
        }

        $userProfile = new User\Profile();

        $userProfile->identifier  = $data->get('id');
        $userProfile->displayName = $data->get('display_name');
        $userProfile->photoURL    = $data->get('logo');
        $userProfile->email       = $data->get('email');

        $userProfile->profileURL = 'http://www.twitch.tv/' . $data->get('name');

        $userProfile->displayName = $userProfile->displayName
                                        ? $userProfile->displayName
                                        : $data->get('name');

        return $userProfile;
    }
}

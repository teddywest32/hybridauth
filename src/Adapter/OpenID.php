<?php
/*!
* HybridAuth
* http://hybridauth.github.io | http://github.com/hybridauth/hybridauth
* (c) 2015 HybridAuth authors | http://hybridauth.github.io/license.html
*/

namespace Hybridauth\Adapter;

use Hybridauth\Exception;
use Hybridauth\Exception\InvalidOpenidIdentifierException;
use Hybridauth\Exception\AuthorizationDeniedException;
use Hybridauth\Exception\InvalidOpenidResponseException;
use Hybridauth\Exception\UnexpectedValueException;
use Hybridauth\Data;
use Hybridauth\HttpClient;
use Hybridauth\User;
use Hybridauth\Thirdparty\OpenID\LightOpenID;

/**
 * This class can be used to simplify the authentication flow of OpenID based service providers.
 *
 * Subclasses (i.e., providers adapters) can either use the already provided methods or override
 * them when necessary.
 */
abstract class OpenID extends AbstractAdapter implements AdapterInterface
{
    /**
    * LightOpenID instance
    *
    * @var object
    */
    protected $openIdClient = null;

    /**
    * Openid provider identifier
    *
    * @var string
    */
    protected $openidIdentifier = '';

    /**
    * Adapter initializer
    *
    * @throws InvalidOpenidIdentifierException
    */
    protected function initialize()
    {
        if ($this->config->exists('openid_identifier')) {
            $this->openidIdentifier = $this->config->get('openid_identifier');
        }

        if (empty($this->openidIdentifier)) {
            throw new InvalidOpenidIdentifierException('OpenID adapter requires an openid_identifier.', 4);
        }

        $hostPort = parse_url($this->endpoint, PHP_URL_PORT);
        $hostUrl  = parse_url($this->endpoint, PHP_URL_HOST);

        if ($hostPort != null) {
            $hostUrl .= ':' . $hostPort;
        }

        // @fixme: add proxy
        $this->openIdClient = new LightOpenID($hostUrl, null);
    }

    /**
    * {@inheritdoc}
    */
    public function authenticate()
    {
        if ($this->isAuthorized()) {
            return true;
        }

        if (! isset($_GET['openid_mode'])) {
            $this->authenticateBegin();
        } else {
            return $this->authenticateFinish();
        }
    }

    /**
    * {@inheritdoc}
    */
    public function isAuthorized()
    {
        return (bool) $this->storage->get($this->providerId . '.user');
    }

    /**
    * {@inheritdoc}
    */
    public function disconnect()
    {
        $this->storage->delete($this->providerId . '.user');

        return true;
    }

    /**
    * Initiate the authorization protocol
    *
    * Include and instantiate LightOpenID
    */
    public function authenticateBegin()
    {
        $this->openIdClient->identity  = $this->openidIdentifier;
        $this->openIdClient->returnUrl = $this->endpoint;
        $this->openIdClient->required  = [
            'namePerson/first'       ,
            'namePerson/last'        ,
            'namePerson/friendly'    ,
            'namePerson'             ,
            'contact/email'          ,
            'birthDate'              ,
            'birthDate/birthDay'     ,
            'birthDate/birthMonth'   ,
            'birthDate/birthYear'    ,
            'person/gender'          ,
            'pref/language'          ,
            'contact/postalCode/home',
            'contact/city/home'      ,
            'contact/country/home'   ,

            'media/image/default'    ,
        ];

        HttpClient\Util::redirect($this->openIdClient->authUrl());
    }

    /**
    * Finalize the authorization process.
    *
    * @throws AuthorizationDeniedException
    * @throws UnexpectedValueException
    */
    public function authenticateFinish()
    {
        if ($this->openIdClient->mode == 'cancel') {
            throw new AuthorizationDeniedException('User has cancelled the authentication.');
        }

        if (! $this->openIdClient->validate()) {
            throw new UnexpectedValueException('Invalid response received.');
        }

        $openidAttributes = $this->openIdClient->getAttributes();

        if (! $this->openIdClient->identity) {
            throw new UnexpectedValueException('Provider returned an expected response.');
        }

        $userProfile = $this->fetchUserProfile($openidAttributes);

        /* with openid providers we only get user profiles once, so we store it */
        $this->storage->set($this->providerId . '.user', $userProfile);
    }

    /**
    * Fetch user profile from received openid attributes
    */
    protected function fetchUserProfile($openidAttributes)
    {
        $data = new Data\Collection($openidAttributes);

        $userProfile = new User\Profile();

        $userProfile->identifier  = $this->openIdClient->identity;

        $userProfile->firstName   = $data->get('namePerson/first');
        $userProfile->lastName    = $data->get('namePerson/last');
        $userProfile->email       = $data->get('contact/email');
        $userProfile->language    = $data->get('pref/language');
        $userProfile->country     = $data->get('contact/country/home');
        $userProfile->zip         = $data->get('contact/postalCode/home');
        $userProfile->gender      = $data->get('person/gender');
        $userProfile->photoURL    = $data->get('media/image/default');
        $userProfile->birthDay    = $data->get('birthDate/birthDay');
        $userProfile->birthMonth  = $data->get('birthDate/birthMonth');
        $userProfile->birthYear   = $data->get('birthDate/birthDate');

        $userProfile = $this->fetchUserGender($userProfile, $data->get('person/gender'));
        
        $userProfile = $this->fetchUserDisplayName($userProfile, $data);

        return $userProfile;
    }

    /**
    * Extract users display names
    */
    protected function fetchUserDisplayName($userProfile, $data)
    {
        $userProfile->displayName = $data->get('namePerson');

        $userProfile->displayName = $userProfile->displayName
                                        ? $userProfile->displayName
                                        : $data->get('namePerson/friendly');

        $userProfile->displayName = $userProfile->displayName
                                        ? $userProfile->displayName
                                        : trim($userProfile->firstName . ' ' . $userProfile->lastName);

        return $userProfile;
    }

    /**
    * Extract users gender
    */
    protected function fetchUserGender($userProfile, $gender)
    {
        $gender = strtolower($gender);

        if ('f' == $gender) {
            $gender = 'female';
        }

        if ('m' == $gender) {
            $gender = 'male';
        }

        $userProfile->gender = $gender;

        return $userProfile;
    }

    /**
    * OpenID only provide the user profile one. This method will attempt to retrieve the profile from storage.
    */
    public function getUserProfile()
    {
        $userProfile = $this->storage->get($this->providerId . '.user');

        if (! is_object($userProfile)) {
            throw new UnexpectedValueException('Provider returned an expected response.');
        }

        return $userProfile;
    }
}

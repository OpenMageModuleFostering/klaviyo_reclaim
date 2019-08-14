<?php
class Klaviyo_Reclaim_KlaviyoController extends Mage_Adminhtml_Controller_Action
{
    const OAUTH_CALLBACK_URL = 'https://www.klaviyo.com/integrations/auth/magento';

    const OAUTH_CONSUMER_NAME = 'Klaviyo REST';

    /**
     * Generate OAuth keys, store them into the Klaviyo configuration, and display them.
     */
    public function oauthkeysAction()
    {
        $oauthHelper = Mage::helper('oauth');

        $consumerKey = $oauthHelper->generateConsumerKey();
        $consumerSecret = $oauthHelper->generateConsumerSecret();

        $consumerModel = Mage::getModel('oauth/consumer');

        $consumerData = array(
            'name' => self::OAUTH_CONSUMER_NAME,
            'key' => $consumerKey,
            'secret' => $consumerSecret);

        $consumerModel->addData($consumerData);
        $consumerModel->save();

        $token = Mage::getModel('oauth/token');

        $token->createRequestToken($consumerModel->getId(), self::OAUTH_CALLBACK_URL);
        $token->convertToAccess();

        $requestToken = $token->getToken();
        $requestTokenSecret = $token->getSecret();

        $user = Mage::getSingleton('admin/session')->getUser();
        $token->authorize($user->getId(), Mage_Oauth_Model_Token::USER_TYPE_ADMIN);

        $reclaimHelper = Mage::helper('klaviyo_reclaim');
        $reclaimHelper->setConsumerKey($consumerKey);
        $reclaimHelper->setConsumerSecret($consumerSecret);
        $reclaimHelper->setAuthorizationToken($requestToken);
        $reclaimHelper->setAuthorizationSecret($requestTokenSecret);

        // redirect to the extension configuration page
        $this->_redirectUrl(Mage::helper('adminhtml')->getUrl('adminhtml/system_config/edit/section/reclaim'));
        return $this;
    }
}

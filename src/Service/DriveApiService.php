<?php

namespace App\Vatri\GoogleDriveBundle\Service;

use function dd;
use Google_Service_Drive_DriveFile;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class DriveApiService
{

	/**
	 * @var \SessionInterface
	 **/
	private $session;

	/**
	 * @var ParameterBagInterface
	 **/
	private $parameters;
	
	/**
	 * @var string $access_token_key Key in SESSION where we store Drive access token
	 **/
	private $access_token_key;

	/**
	 * @var Google_Service_Drive $drive
	 **/
	private $drive;

	public function __construct(SessionInterface $session, ParameterBagInterface $parameters)
    {
		$this->session    = $session;
		$this->parameters = $parameters;

		$this->access_token_key = $parameters->get('vatri_google_drive.session_access_token_key');

		// $this->drive = $this->generateDrive();

	}

	/**

https://developers.google.com/identity/protocols/OAuth2WebServer#refresh

< They say:
If you use a Google API Client Library, the client object refreshes the access token as needed as long as you configure that object for offline access.


	 * @param string $redirect_route_name Route to redirect to after successful login.
	 *
	 * @return 'ok' on valid. Current route_name on invalid.
	 **/
	// public function validateAndRefreshToken($redirect_route_name){
		
	// 	$access_token = $this->session->get('access_token');

	// 	// 15 mins = 15x60s
	// 	if(time() - $access_token['created'] > 15 * 60){
	// 	// if(time() - $access_token['created'] >= $access_token['expires_in']){
			
	// 		$this->session->set('vatri_google_drive.redirect_route_on_auth', $redirect_route_name);

	// 		// $this->redirectToRoute('vatri_google_drive_auth');
	// 		return 'vatri_google_drive_auth';
	// 	}

	// 	return 'ok';
	// }

    /**
     * Build $drive property automaticaly
     *
     * @return \Google_Service_Drive
     */
	private function buildDrive() : \Google_Service_Drive
	{
		$client = new \Google_Client();
		$client->setAuthConfig( $this->parameters->get('vatri_google_drive.credentials_file') );
		$client->addScope( \Google_Service_Drive::DRIVE ); // should have all permissions
		$client->setAccessToken( $this->session->get($this->access_token_key) );

		$access_token = $this->session->get($this->access_token_key);
		if(isset($access_token['refresh_token'])){
			$client->fetchAccessTokenWithRefreshToken();
		}
// dump($this->session->get('access_token'));
		$drive = new \Google_Service_Drive( $client );

		return $drive;
	}

	public function setDrive(\Google_Service_Drive $drive)
	{
		$this->drive = $drive;
	}

    /**
    * Return Drive or generate if not set manualy
    *
    * @return Google_Service_Drive|\Google_Service_Drive
    */
    public function getDrive()
    {

// setDrive and getDrive is used for unit-tests...
        if($this->drive == null){
            return $this->buildDrive();
        }
        return $this->drive;
    }

    /**
     * Create Google Drive folder
     * @param $path For example: /path/to/folder will create 3 folders: path, to and folder.
     *
     * @param null $parentId
     * @return array|null
     */
	public function createFolder($path, $parentId = null) :? array
	{

		$drive = $this->buildDrive();

		$folders = explode("/", $path);

		$error = '';

		try{

			foreach ($folders as $folder_name) {
				
				$fileParams = [
					'name' => $folder_name,
		    		'mimeType' => 'application/vnd.google-apps.folder'
		    	];
		    	if($parentId != null){
		    		$fileParams['parents'] = [ $parentId ];
		    	}

				$fileMetadata = new \Google_Service_Drive_DriveFile( $fileParams );

				$res = $drive->files->create( $fileMetadata, [
					'fields' => 'id'
				]);

				$parentId = $res->id;

			}
		} catch(\Exception $e){
			$error = $e->getMessage();
			$parentId = null;
		} 
		return [
			'error'    => $error,
			'parentId' => $parentId
		];
	}


    /**
     * @param string $folderId
     * @param bool $inTrash
     * @return bool
     */
	public function folderExists(?string $folderId, bool $inTrash = true)
	{
		$drive = $this->buildDrive();

		$res = null;
		try{
			$res = $drive->files->get($folderId, [
			    'fields' => 'id,trashed'
            ]);

			if($res->trashed){
			    return false;
            }
		} catch( \Exception $e){
			//todo: log message
//			 echo $e->getMessage();
			return false;
		}

		return isset($res->id);

	}

	/**
	 * Remove file - completely (from Trash as well).
	 *
	 * @param string $fileId ID of Drive file
	 * @return bool
	 **/
	public function deleteFile(string $fileId)
	{
		
		$drive = $this->buildDrive();

		try{
			$res = $drive->files->delete($fileId);
		} catch( \Exception $e){
			return false;
		}

		return true;

	}

    /**
     * @param string|null $parentId
     * @param bool|null $includeTrashed
     * @param bool|null $onlyStarred
     *
     * @return \Google_Service_Drive_FileList|null
     */
	public function listFiles(?string $parentId = '', ?bool $includeTrashed = true, ?bool $onlyStarred = false) : ?\Google_Service_Drive_FileList
	{
        $drive = $this->getDrive();

        $q = " trashed = " . ($includeTrashed ? 'true' : 'false');

        if($parentId != ''){
            $q .= " and parents in '$parentId' ";
        }
        if($onlyStarred == true){
            $q .= " and starred = true ";
        }

		$res = $drive->files->listFiles([
			'q' => $q,
            'fields' => "files/*",
		]);

        return $res;
    }

    /**
     * Copy existing file to same or another folder.
     *
     * @param string $fileId
     * @param string|null $parentId Where to move copied file.
     */
    public function copyFile(string $fileId, ?string $parentId = null)
    {
//        $drive = $this->generateDrive();
        $drive = $this->getDrive();

        $out = [
            'fileId' => '',
            'error'  => ''
        ];

        $driveFile = new \Google_Service_Drive_DriveFile();
        if($parentId != null){
            $driveFile->setParents([$parentId]);
        }

        try{
            $res = $drive->files->copy($fileId, $driveFile);
//dd($res);
            $out['fileId'] = $res->getId();
        } catch (\Exception $e){
            $out['error'] = $e->getMessage();
        }

        return $out;
    }

    /**
     * @param string $name Name to search for
     * @param string $parentId
     */
//     public function find(string $name, string $parentId = ''): ?Google_Service_Drive_FileList
//     {
//         $q = "name = '$name'";
//         if ($parentId != '') {
//             $q .= " and parents in '$parentId' ";
//         }
//         $res = $this->generateDrive()->files->listFiles([
//             'q' => $q
//         ]);

//         return $res;
//     }

	/**
	 * @return ??
	 **/
	public function uploadFile(UploadedFile $file, $parentId = null)
	{
		$drive = $this->buildDrive();

		$driveFile = new \Google_Service_Drive_DriveFile();
  		$driveFile->setName($file->getClientOriginalName());
  		if($parentId){
  			$driveFile->setParents([$parentId]);
  		}

  		try{

			$result = $drive->files->create(
				$driveFile,
				array(
					'data' => file_get_contents($file->getPathname()),
					'mimeType' => $file->getClientMimeType(),
					'uploadType' => 'multipart',
					// 'parents'   => $parentId == null ? [] : [$parentId]
				)
			);
  		} catch (\Exception $e) {
  			//todo: log message
  			return false;
  		}
		return true;
	}

    /**
     * @param string $fileId
     * @param string $key
     * @param string $value
     */
	public function setStarred(string $fileId, bool $starred)
    {
//        $fileId .= '1';
        $file = new Google_Service_Drive_DriveFile();
        $file->setStarred($starred);
        try{
            $res = $drive = $this->getDrive()->files->update($fileId, $file);
            return true;
        } catch (\Exception $e){
            return false;
        }
    }

	/**
	 * @return bool
	 **/
	public function isTokenExpired()
    {
		$access_token = $this->session->get($this->access_token_key);
// dump($access_token);
// die;
		if( empty($access_token) || ! isset($access_token['access_token']) || ! isset($access_token['refresh_token']) ){
			return true;
		}


		// Check if token will expire in 10 or less minutes:
		return $access_token['expires_in'] + $access_token['created'] <= time() - 10*60;
	}

	/**
	 * @return string Symfony route name
	 **/
	public function getAuthRouteName()
    {
		return 'vatri_google_drive_auth';
	}

    /**
     * @param string $route_name
     */
	public function setRedirectPathAfterAuth(string $path)
    {
        $this->session->set(
            $this->parameters->get('vatri_google_drive.session.key.redirect_path_after_auth'),
            $path
        );
    }
}
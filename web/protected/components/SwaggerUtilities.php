<?php
/**
 * This file is part of the DreamFactory Services Platform(tm) (DSP)
 *
 * DreamFactory Services Platform(tm) <http://github.com/dreamfactorysoftware/dsp-core>
 * Copyright 2012-2013 DreamFactory Software, Inc. <developer-support@dreamfactory.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
use Platform\Yii\Utility\Pii;
use Swagger\Swagger;
use Kisma\Core\Utility\Option;
use Kisma\Core\Utility\Log;

/**
 * SwaggerUtilities
 * A utilities class to handle swagger documentation of the REST API.
 */
class SwaggerUtilities
{
	//*************************************************************************
	//	Constants
	//*************************************************************************

	/**
	 * @var string The private CORS configuration file
	 */
	const SWAGGER_CACHE_DIR = '/swagger/';

	//*************************************************************************
	//	Methods
	//*************************************************************************

	/**
	 * Internal building method builds all static services and some dynamic
	 * services from file annotations, otherwise swagger info is loaded from
	 * database or storage files for each service, if it exists.
	 *
	 * @return array
	 * @throws Exception
	 */
	protected static function buildSwagger()
	{
		$_basePath = Yii::app()->getRequest()->getHostInfo() . '/rest';
		$_swaggerPath = Pii::getParam( 'private_path' ) . static::SWAGGER_CACHE_DIR;

		// create root directory if it doesn't exists
		if ( !file_exists( $_swaggerPath ) )
		{
			@\mkdir( $_swaggerPath, 0777, true );
		}

		// generate swagger output from file annotations
		$_scanPath = Yii::app()->basePath . '/components';
		$_swagger = Swagger::discover( $_scanPath );
		$_swagger->setDefaultBasePath( $_basePath );
		$_swagger->setDefaultApiVersion( Versions::API_VERSION );
		$_swagger->setDefaultSwaggerVersion('1.1');

		/**
		 * build services from database
		 *
		 * @var CDbCommand $command
		 */
		$command = Yii::app()->db->createCommand();
		$result = $command->select( 'api_name,type,description' )
				  ->from( 'df_sys_service' )
				  ->order( 'api_name' )
				  ->where( 'type != :t', array( ':t' => 'Remote Web Service' ) )
				  ->queryAll();

		// add static services
		$other = array(
			array( 'api_name' => 'user', 'type' => 'user', 'description' => 'User Login' ),
			array( 'api_name' => 'system', 'type' => 'system', 'description' => 'System Configuration' )
		);
		$result = array_merge( $other, $result );

		// gather the services
		$services = array();
		foreach ( $result as $service )
		{
			$services[] = array(
				'path'        => '/' . $service['api_name'],
				'description' => $service['description']
			);
			$serviceName = $apiName = Option::get( $service, 'api_name', '' );
			$replacePath = false;
			$_type = Option::get( $service, 'type', '' );
			switch ( $_type )
			{
				case 'Remote Web Service':
					$serviceName = '{web}';
					$replacePath = true;
					// look up definition file and return it
					break;
				case 'Local File Storage':
				case 'Remote File Storage':
					$serviceName = '{file}';
					$replacePath = true;
					break;
				case 'Local SQL DB':
				case 'Remote SQL DB':
					$serviceName = '{sql_db}';
					$replacePath = true;
					break;
				case 'Local SQL DB Schema':
				case 'Remote SQL DB Schema':
					$serviceName = '{sql_schema}';
					$replacePath = true;
					break;
				case 'Local Email Service':
				case 'Remote Email Service':
					$serviceName = '{email}';
					$replacePath = true;
					break;
				default:
					break;
			}

			if ( false === ( $_content = $_swagger->getResource( '/' . $serviceName ) ) )
			{
				Log::error( "No swagger info for service $apiName." );
			}

			if ( $replacePath )
			{
				// replace service type placeholder with api name for this service instance
				$_content = str_replace( '/' . $serviceName, '/' . $apiName, $_content );
			}

			// cache it to a file for later access
			$_filePath = $_swaggerPath . $apiName . '.json';
			if ( false === file_put_contents( $_filePath, $_content ) )
			{
				Log::error( "Failed to write cache file $_filePath." );
			}
		}

		// cache main api listing file
		$_out = array(
			'apiVersion'     => Versions::API_VERSION,
			'swaggerVersion' => '1.1',
			'basePath'       => $_basePath,
			'apis'           => $services
		);
		$_filePath = $_swaggerPath . '_.json';
		if ( false === file_put_contents( $_filePath, $_swagger->jsonEncode( $_out ) ) )
		{
			Log::error( "Failed to write cache file $_filePath." );
		}

		return $_out;
	}

	/**
	 * Main retrieve point for a list of swagger-able services
	 * This builds the full swagger cache if it does not exist
	 *
	 * @return string The JSON contents of the swagger api listing.
	 * @throws \InternalServerErrorException
	 */
	public static function getSwagger()
	{
		$_swaggerPath = Pii::getParam( 'private_path' ) . static::SWAGGER_CACHE_DIR;
		$_filePath = $_swaggerPath . '_.json';
		if ( !file_exists( $_filePath ) )
		{
			static::buildSwagger();
			if ( !file_exists( $_filePath ) )
			{
				throw new \InternalServerErrorException( "Failed to create swagger cache." );
			}
		}

		if ( false === ( $_content = file_get_contents( $_filePath ) ) )
		{
			throw new \InternalServerErrorException( "Failed to retrieve swagger cache." );
		}

		return $_content;
	}

	/**
	 * Main retrieve point for each service
	 *
	 * @param string $service Which service (api_name) to retrieve.
	 *
	 * @throws \InternalServerErrorException
	 * @return string The JSON contents of the swagger service.
	 */
	public static function getSwaggerForService( $service )
	{
		$_swaggerPath = Pii::getParam( 'private_path' ) . static::SWAGGER_CACHE_DIR;
		$_filePath = $_swaggerPath . $service . '.json';
		if ( !file_exists( $_filePath ) )
		{
			static::buildSwagger();
			if ( !file_exists( $_filePath ) )
			{
				throw new \InternalServerErrorException( "Failed to create swagger cache." );
			}
		}

		if ( false === ( $_content = file_get_contents( $_filePath ) ) )
		{
			throw new \InternalServerErrorException( "Failed to retrieve swagger cache." );
		}

		return $_content;
	}

	/**
	 * Clears the cached files produced by the swagger annotations
	 */
	public static function clearCache()
	{
		$_swaggerPath = Pii::getParam( 'private_path' ) . static::SWAGGER_CACHE_DIR;
		if ( file_exists( $_swaggerPath ) )
		{
			$files = array_diff( scandir( $_swaggerPath ), array( '.', '..' ) );
			foreach ( $files as $file )
			{
				@unlink( $_swaggerPath . $file );
			}
		}
	}
}

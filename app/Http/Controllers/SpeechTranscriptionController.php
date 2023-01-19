<?php

namespace App\Http\Controllers;

use Google\Cloud\Storage\StorageClient;
use Illuminate\Http\Request;

use Google\Cloud\Speech\V1\RecognitionConfig\AudioEncoding;
use Google\Cloud\Speech\V1\RecognitionConfig;
use Google\Cloud\Speech\V1\StreamingRecognitionConfig;
use Google\Cloud\Speech\V1\SpeechClient;
use Google\Cloud\Speech\V1\RecognitionAudio;
use Illuminate\Support\Facades\Storage;

/*use Google\Cloud\Speech\V1p1beta1\RecognitionConfig\AudioEncoding;
use Google\Cloud\Speech\V1p1beta1\RecognitionConfig;
use Google\Cloud\Speech\V1p1beta1\StreamingRecognitionConfig;
use Google\Cloud\Speech\V1p1beta1\SpeechClient;
use Google\Cloud\Speech\V1p1beta1\RecognitionAudio;*/



class SpeechTranscriptionController extends Controller
{
    private $bucket_name =  '';
    public function __construct(){
        $this->bucket_name = env('GOOGLE_CLOUD_BUCKET');
    }

    public function transcribe(Request $request){
        if ($request->hasFile('audio')) {
            try {
                $storage = new StorageClient([
                    'keyFilePath' => public_path('auth.json'),
                ]);

                $bucket = $storage->bucket($this->bucket_name);

                //get filename with extension
                $filenamewithextension = $request->file('audio')->getClientOriginalName();

                //get filename without extension
                $filename = pathinfo($filenamewithextension, PATHINFO_FILENAME);

                //get file extension
                $extension = $request->file('audio')->getClientOriginalExtension();

                //filename to store
                $filenametostore = $filename.'_'.uniqid().'.'.$extension;

                Storage::put('public/uploads/'. $filenametostore, fopen($request->file('audio'), 'r+'));

                $filepath = storage_path('app/public/uploads/'.$filenametostore);

                $object = $bucket->upload(
                    fopen($filepath, 'r'),
                    [
                        'predefinedAcl' => 'publicRead'
                    ]
                );

                // delete file from local disk
                Storage::delete('public/uploads/'. $filenametostore);

                // transcribe the audio
                $this->speechToText($filenametostore);
            } catch(Exception $e) {
                echo $e->getMessage();
            }
        }
    }

    public function speechToText($fileName)
    {
        // change these variables if necessary
        $encoding = AudioEncoding::ENCODING_UNSPECIFIED;
        $languageCode = 'en-US';
        $audioChannelCount = 2;

        $uri = "gs://$this->bucket_name/$fileName";

        // set string as audio content
        $audio = (new RecognitionAudio())->setUri($uri);

        // set config
        $config = (new RecognitionConfig())
            ->setEncoding($encoding)
            ->setAudioChannelCount($audioChannelCount)
            ->setEnableAutomaticPunctuation(true)
            ->setLanguageCode($languageCode);

        // create the speech client
        $client = new SpeechClient([
            'credentials' => json_decode(file_get_contents(public_path('auth.json')),true),
        ]);

        // create the asyncronous recognize operation
        $operation = $client->longRunningRecognize($config, $audio);
        $operation->pollUntilComplete();

        if ($operation->operationSucceeded()) {
            $response = $operation->getResult();

            // each result is for a consecutive portion of the audio. iterate
            // through them to get the transcripts for the entire audio file.
            $transcript = '';
            foreach ($response->getResults() as $result) {
                $alternatives = $result->getAlternatives();
                $mostLikely = $alternatives[0];
                $transcript .= $mostLikely->getTranscript();
            }

            echo ("<b>Filename</b>: $fileName </br><b>Transcript</b>: $transcript");

            // delete that object after preparing transcript
            $this->delete_object($fileName);
            exit();
        } else {
            print_r($operation->getError());
        }

        $client->close();
    }


    public function getFileInfo(Request $request)
    {
        $cloudPath = $request->get('cloudPath');
        try {
            $storage = new StorageClient([
                'keyFilePath' => public_path('auth.json'),
            ]);
        } catch (Exception $e) {
            // maybe invalid private key ?
            print $e;
            return false;
        }
        // set which bucket to work in

        $bucketName = env('GOOGLE_CLOUD_BUCKET');
        $bucket = $storage->bucket($bucketName);
        $object = $bucket->object($cloudPath);
        if (!$object->exists()) {
            echo "Object: '$cloudPath' is not exists";
            exit();
        }
        return $object->info();
    }

    public function delete_object($objectName)
    {
        $storage = new StorageClient([
            'keyFilePath' => public_path('auth.json'),
        ]);
        $bucket = $storage->bucket($this->bucket_name);
        $object = $bucket->object($objectName);
        $object->delete();
    }
}

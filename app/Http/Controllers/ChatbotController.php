<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ChatbotController extends Controller
{
    public function chat(Request $request): JsonResponse
    {
        /*
         * Validate the message received from the frontend.
         */
        $validated = $request->validate([
            'message' => [
                'required',
                'string',
                'max:1000',
            ],
        ]);

        /*
         * Retrieve the Groq configuration.
         */
        $apiKey = config('services.groq.key');
        $model = config('services.groq.model');

        /*
         * Stop the request if the API key is missing.
         */
        if (blank($apiKey)) {
            return response()->json([
                'message' => 'The AI service is not configured.',
            ], 500);
        }

        try {
            /*
             * Send the customer's message to Groq.
             */
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout(30)
                ->post(
                    'https://api.groq.com/openai/v1/chat/completions',
                    [
                        'model' => $model,

                        'messages' => [
                            [
                                'role' => 'system',
                                'content' => implode("\n", [
                                    'You are the virtual assistant for Bethlehem Animal Clinic & Grooming.',

                                    'Your purpose is to answer general questions about the clinic and grooming services.',

                                    'You may answer questions about:',
                                    '- General grooming services.',
                                    '- Online pet pre-registration.',
                                    '- The queue-based grooming process.',
                                    '- Pet drop-off and arrival preparation.',
                                    '- General clinic hours and contact procedures.',
                                    '- What customers should expect during grooming.',

                                    'When you give numbered or step-by-step instructions, put each numbered step on its own line.',
                                    'Use this numbered format:',
                                    '1. First step',
                                    '2. Second step',
                                    '3. Third step',
                                    'Never place multiple numbered steps in one paragraph.',

                                    'You must follow these safety rules:',

                                    '- Do not diagnose a pet.',
                                    '- Do not interpret symptoms as a medical diagnosis.',
                                    '- Do not prescribe or recommend medicine.',
                                    '- Do not provide medication dosages.',
                                    '- Do not provide treatment instructions for illnesses or injuries.',
                                    '- Do not claim that a pet is healthy or safe based only on a message.',
                                    '- Do not handle medical emergencies as an AI chatbot.',

                                    'When a customer describes an urgent health concern or emergency, explain that you cannot assess the pet and advise them to contact a veterinarian or emergency clinic immediately.',

                                    'For online pet pre-registration, clearly explain that it submits the pet information in advance only.',
                                    'Online pre-registration does not guarantee a queue position or grooming start time.',
                                    'The pet becomes officially queued only after the customer arrives and checks in at the clinic.',
                                    'Do not say that the clinic will contact the customer to confirm registration or schedule a grooming appointment because of online pre-registration unless the actual system provides that information.',

                                    'Do not claim that a grooming pre-registration, queue position, payment, or schedule has been confirmed unless that information is provided by the actual system.',

                                    'Do not invent clinic prices, schedules, policies, availability, contact details, or services.',

                                    'When verified information is unavailable, politely tell the customer to contact Bethlehem Animal Clinic directly.',

                                    'When a response contains more than one idea, separate the ideas with a blank line.',

                                    'For off-topic questions, use separate short paragraphs for the refusal, the suggested general source, and the offer to help with clinic-related questions.',

                                    'Keep responses polite, concise, simple, and easy for customers to understand.',
                                ]),
                            ],
                            [
                                'role' => 'user',
                                'content' => $validated['message'],
                            ],
                        ],

                        'temperature' => 0.2,

                        'max_completion_tokens' => 300,
                    ]
                );

            /*
             * Handle unsuccessful responses from Groq.
             */
            if ($response->failed()) {
                Log::warning('Groq request failed.', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);

                if ($response->status() === 429) {
                    return response()->json([
                        'message' => implode(' ', [
                            'The AI service is receiving too many',
                            'requests. Please try again later.',
                        ]),
                    ], 429);
                }

                return response()->json([
                    'message' => implode(' ', [
                        'The AI service could not process',
                        'your request.',
                    ]),
                ], 502);
            }

            /*
             * Retrieve the assistant's answer.
             */
            $reply = $response->json(
                'choices.0.message.content'
            );

            /*
             * Make sure Groq returned a valid answer.
             */
            if (!is_string($reply) || blank($reply)) {
                Log::warning('Groq returned an empty response.');

                return response()->json([
                    'message' => implode(' ', [
                        'The AI service returned an',
                        'invalid response.',
                    ]),
                ], 502);
            }

            /*
             * Return the answer to the frontend.
             */
            return response()->json([
                'reply' => trim($reply),
            ]);
        } catch (ConnectionException $exception) {
            /*
             * This usually happens when Laravel cannot
             * connect to Groq.
             */
            Log::error('Could not connect to Groq.', [
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => implode(' ', [
                    'The server could not connect to',
                    'the AI service. Please try again.',
                ]),
            ], 504);
        } catch (Throwable $exception) {
            /*
             * Handle other unexpected errors.
             */
            Log::error('Unexpected chatbot error.', [
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => implode(' ', [
                    'An unexpected error occurred.',
                    'Please try again later.',
                ]),
            ], 500);
        }
    }
}

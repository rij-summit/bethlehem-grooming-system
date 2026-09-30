// Opt-in semantic evaluation against the running local app and its configured Groq model.
// No credentials or customer records are used. Never run as part of offline unit tests.
// Run: node tests/chatbot-live-regression.cjs --live
const assert = require('node:assert/strict');

if (!process.argv.includes('--live')) {
  console.log('Use --live to run synthetic chatbot questions through the local app and Groq.');
  process.exit(0);
}

const base = process.env.CHATBOT_TEST_URL || 'http://127.0.0.1:8000';
const sleep = (ms) => new Promise(resolve => setTimeout(resolve, ms));
const cases = [
  ['appointments', ['Do you handle appointments?'], /do not use appointments/i],
  ['walkin', ['Do you handle walkin?'], /accepts walk-ins/i],
  ['walk in', ['Do you handle walk in?'], /accepts walk-ins/i],
  ['walk-in', ['Do you handle walk-in?'], /accepts walk-ins/i],
  ['Taglish', ['Pwede walk in?'], /walk-ins/i],
  ['huskey follow-up', ['I have a large huskey, how long will it take?', 'Puppy cut'], /around 2 hours/i, /short\/summer cut|puppy cut/i],
  ['large husky trim', ['Large husky puppy cut, how long?'], /around 2 hours/i],
  ['giant trim', ['How long for a giant poodle trim?'], /around 2 to 3 hours/i],
  ['kalbo follow-up', ['How long for kalbo?', 'Large'], /around 30 minutes/i, /what size/i],
  ['hours follow-up', ['What time do you open today?', 'How about tomorrow?'], /configured hours|closed on/i, /\d+:\d+\s*[AP]M|closed on/i],
  ['sign up', ['How do I create an account?'], /Sign Up/i],
  ['add pet', ['I already signed in, how do I add my pet?'], /My Pets|Add Pet/i],
  ['tracker', ['Where can I see if my pet is already being groomed?'], /Grooming Tracker/i],
  ['pre-register help', ["I don't know how to pre register"], /Pre-register/i],
  ['unrelated essay', ['Write my school essay about Philippine history.'], /^I can only answer questions related to Bethlehem Animal Clinic\.$/],
  ['puppy cut follow-up', ['How long does puppy cut take?', 'Large'], /around 2 hours/i, /what size/i],
  ['walk-ins grooming follow-up', ['Do you accept walkins?', 'What about grooming?'], /accept(?:s)? walk[-\u2010-\u2015 ]?ins?[\s\S]*grooming/i],
  ['undefined style', ['How long for a teddy bear cut on a large dog?'], /no verified timing/i],
].filter(([label]) => !process.env.CHATBOT_TEST_CASE || process.env.CHATBOT_TEST_CASE.split('|').includes(label));

(async () => {
  let failures = 0;
  let requests = 0;
  for (const [label, questions, expected, firstExpected] of cases) {
    let history = [];
    try {
      for (let index = 0; index < questions.length; index++) {
        // Keep live checks below typical free-tier token limits without changing app/model settings.
        if (requests++) await sleep(35000);
        const response = await fetch(`${base}/api/chatbot`, {
          method: 'POST',
          headers: {'Content-Type': 'application/json', Accept: 'application/json'},
          body: JSON.stringify({message: questions[index], history}),
          signal: AbortSignal.timeout(45000),
        });
        assert.equal(response.status, 200, `HTTP ${response.status}`);
        const result = await response.json();
        assert.notEqual(result.source, 'local_fallback', 'Groq unavailable/rate limited; this does not count as a semantic pass');
        const reply = result.reply;
        if (index === 0 && firstExpected) assert.match(reply, firstExpected);
        if (index === questions.length - 1) assert.match(reply, expected);
        if (label === 'sign up') assert.match(reply, /email/i);
        if (label === 'sign up') assert.match(reply, /link/i);
        if (['large husky trim', 'huskey follow-up'].includes(label)) assert.doesNotMatch(reply, /2 to 4 hours/);
        if (label === 'giant trim') assert.match(reply, /4 hours/);
        if (label === 'appointments') assert.match(reply, /check-in/i);
        if (label === 'pre-register help') assert.match(reply, /Clinic Visit/i);
        if (['sign up', 'add pet', 'tracker', 'pre-register help'].includes(label)) assert.equal(result.source, 'customer_guide');
        if (label === 'tracker') assert.doesNotMatch(reply, /Quick Actions/i);
        if (label === 'add pet') assert.match(reply, /Save Pet/i);
        if (label === 'walk-ins grooming follow-up') assert.match(reply, /check[-\u2010-\u2015 ]?in/i);
        if (label === 'walk-ins grooming follow-up') assert.doesNotMatch(reply, /not guaranteed until|guaranteed (?:after|upon)/i);
        history.push({role:'user', content:questions[index]}, {role:'assistant', content:reply});
        history = history.slice(-8);
        console.log(JSON.stringify({label, question:questions[index], source:result.source, reply}));
      }
      console.log(`PASS: ${label}`);
    } catch (error) {
      failures++;
      console.error(`FAIL: ${label}: ${error.message}`);
    }
  }
  console.log(`${cases.length - failures}/${cases.length} live scenarios passed.`);
  process.exitCode = failures ? 1 : 0;
})();

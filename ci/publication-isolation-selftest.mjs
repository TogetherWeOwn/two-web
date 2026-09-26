#!/usr/bin/env node
// Offline publication-policy regressions. Evaluate the actual deploy condition,
// not a second implementation of it. These tests cannot certify GitHub settings:
// runner-group access and organization-secret grants need independent readback.
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const root = fileURLToPath(new URL('../', import.meta.url));
const workflows = new Map(readdirSync(`${root}.github/workflows`)
  .filter(name => /\.ya?ml$/.test(name))
  .map(name => [name, readFileSync(`${root}.github/workflows/${name}`, 'utf8')]));
let checks = 0;
function check(name, fn) {
  fn();
  checks += 1;
  console.log(`PASS: ${name}`);
}

function assertRunners(files) {
  let jobs = 0;
  for (const [name, text] of files) {
    const body = text.split(/^jobs:\s*$/m)[1];
    assert.ok(body, `${name}: jobs must be inspectable`);
    const blocks = [...body.matchAll(/^  [\w-]+:\s*\n([\s\S]*?)(?=^  [\w-]+:\s*$|(?![\s\S]))/gm)];
    assert.ok(blocks.length, `${name}: expected jobs`);
    for (const [block] of blocks) {
      assert.match(block, /^    runs-on: ubuntu-latest\s*$/m, `${name}: each job must use ubuntu-latest`);
      jobs += 1;
    }
  }
  assert.ok(jobs >= 9, 'must inspect all nine publication jobs');
}

check('every job uses ubuntu-latest', () => assertRunners(workflows));
check('no privileged PR trigger or non-deploy secret references', () => {
  for (const [name, text] of workflows) {
    assert.doesNotMatch(text, /^\s*pull_request_target\s*:/m, name);
    if (name !== 'deploy.yml') assert.doesNotMatch(text, /\bsecrets\s*(?:\.|\[)/, name);
  }
});

const deploy = workflows.get('deploy.yml');
function deployCondition(text) {
  const expression = text.match(/^    if: >-\n([\s\S]*?)(?=^    \S)/m)?.[1].trim();
  assert.ok(expression, 'staging deploy condition must be inspectable');
  // This workflow deliberately uses only properties, quoted strings, ==, &&,
  // || and parentheses, shared by Actions expressions and JavaScript.
  assert.match(expression, /^[\w\s.'=&|()/-]+$/);
  return github => vm.runInNewContext(expression, { github }, { timeout: 100 }) === true;
}
const repository = 'TogetherWeOwn/two-web';
function event({ event_name = 'workflow_run', ref = 'refs/heads/main',
  source = 'push', head_repository = repository, head_branch = 'main',
  conclusion = 'success' } = {}) {
  return { event_name, ref, repository, event: { workflow_run: {
    event: source, conclusion, head_branch, head_repository: { full_name: head_repository },
  } } };
}
const cases = [
  ['successful main push', event(), true],
  ['failed main push', event({ conclusion: 'failure' }), false],
  ['cancelled main push', event({ conclusion: 'cancelled' }), false],
  ['same-repository PR', event({ source: 'pull_request' }), false],
  ['fork main PR', event({ source: 'pull_request', head_repository: 'contributor/two-web' }), false],
  ['fork main push', event({ head_repository: 'contributor/two-web' }), false],
  ['feature push', event({ head_branch: 'feature' }), false],
  ['manual main deploy', event({ event_name: 'workflow_dispatch' }), true],
  ['manual feature deploy', event({ event_name: 'workflow_dispatch', ref: 'refs/heads/feature' }), false],
  ['unrelated event', event({ event_name: 'pull_request' }), false],
];
const allows = deployCondition(deploy);
for (const [name, input, expected] of cases) {
  check(name, () => assert.equal(allows(input), expected));
}

check('deploy checkout uses only trusted main, without persisted credentials', () => {
  assert.match(deploy, /uses: actions\/checkout@v\d+\n\s+with:\n\s+ref: main\n\s+persist-credentials: false/);
  assert.doesNotMatch(deploy, /uses:.*download-artifact/);
});
check('Dusk retains always-uploaded screenshots without the board token', () => {
  assert.match(workflows.get('ci.yml'), /name: Upload Dusk screenshots\n\s+if: always\(\)\n\s+uses: actions\/upload-artifact@v4/);
});

// Negative controls prove each guard matters to this event matrix. A passing
// happy path alone cannot distinguish a useful test from a vacuous one.
for (const clause of [
  "github.ref == 'refs/heads/main'",
  "github.event.workflow_run.conclusion == 'success'",
  "github.event.workflow_run.event == 'push'",
  "github.event.workflow_run.head_branch == 'main'",
  'github.event.workflow_run.head_repository.full_name == github.repository',
]) {
  check(`reject weakened guard: ${clause}`, () => {
    assert.ok(deploy.includes(clause), 'mutation must apply');
    const weakened = deployCondition(deploy.replace(clause, 'true'));
    assert.ok(cases.some(([, input, expected]) => weakened(input) !== expected));
  });
}
check('detect a job moved back to self-hosted', () => {
  const mutated = new Map(workflows);
  mutated.set('ci.yml', mutated.get('ci.yml').replace('runs-on: ubuntu-latest', 'runs-on: [self-hosted, two-selfhosted]'));
  assert.throws(() => assertRunners(mutated), /each job must use ubuntu-latest/);
});
console.log(`${checks} publication-isolation checks passed`);

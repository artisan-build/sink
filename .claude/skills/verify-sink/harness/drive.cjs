#!/usr/bin/env node

const fs = require('fs')
const path = require('path')
const { chromium } = require('playwright')

const args = Object.create(null)
const viewports = []
for (const raw of process.argv.slice(2)) {
  const match = /^--([^=]+)=(.*)$/.exec(raw)
  if (!match) continue
  if (match[1] === 'viewport') viewports.push(match[2])
  else args[match[1]] = match[2]
}

if (!args.base || !args.out || !args.steps) {
  console.error('usage: drive.cjs --base=URL --out=EVIDENCE --steps=STEPS.json [--name=LABEL] [--viewport=WxH]...')
  process.exit(2)
}
if (viewports.length === 0) viewports.push('1280x800')

const label = args.name || path.basename(args.steps, '.json')
const steps = JSON.parse(fs.readFileSync(args.steps, 'utf8'))
if (!Array.isArray(steps)) throw new Error('step file must contain a JSON array')
fs.mkdirSync(args.out, { recursive: true })
const transcript = path.join(args.out, 'transcript.jsonl')

function interpolate(value, viewport) {
  if (typeof value === 'string') return value.replaceAll('{{viewport}}', viewport)
  if (Array.isArray(value)) return value.map((item) => interpolate(item, viewport))
  if (value && typeof value === 'object') {
    return Object.fromEntries(Object.entries(value).map(([key, item]) => [key, interpolate(item, viewport)]))
  }
  return value
}

function record(entry) {
  fs.appendFileSync(transcript, JSON.stringify({ at: new Date().toISOString(), ...entry }) + '\n')
  console.log(`${entry.ok === false ? 'FAIL' : 'ok  '} [${entry.viewport}] ${entry.verb} ${entry.detail || ''}`)
}

const absoluteUrl = (value) => (/^https?:/.test(value) ? value : args.base + value)

async function screenshot(page, viewport, name) {
  const file = path.join(args.out, `${label}--${viewport}--${name}.png`)
  await page.screenshot({ path: file, fullPage: true })
  return file
}

async function runViewport(browser, viewport) {
  if (!/^\d+x\d+$/.test(viewport)) throw new Error(`invalid viewport ${viewport}`)
  const [width, height] = viewport.split('x').map(Number)
  const context = await browser.newContext({ viewport: { width, height } })
  const page = await context.newPage()
  const consoleErrors = []
  const responseErrors = []
  let lastStatus = null
  let acceptNextDialog = false

  page.on('console', (message) => {
    if (message.type() === 'error') consoleErrors.push(message.text())
  })
  page.on('response', (response) => {
    if (response.status() >= 400) responseErrors.push({ status: response.status(), url: response.url() })
  })
  page.on('dialog', async (dialog) => {
    if (acceptNextDialog) {
      acceptNextDialog = false
      await dialog.accept()
    } else {
      await dialog.dismiss()
    }
  })

  const fail = async (verb, detail) => {
    const file = await screenshot(page, viewport, `FAILED-${verb}`)
    record({ viewport, verb, detail, ok: false, screenshot: file, url: page.url() })
    await context.close()
    throw new Error(`${verb} failed: ${detail}`)
  }

  for (const rawStep of steps) {
    const step = interpolate(rawStep, viewport)
    const keys = Object.keys(step)
    if (keys.length !== 1) await fail('step', 'each step must contain exactly one verb')
    const verb = keys[0]
    const value = step[verb]

    try {
      if (verb === 'goto') {
        const response = await page.goto(absoluteUrl(value), { waitUntil: 'domcontentloaded' })
        lastStatus = response ? response.status() : null
        record({ viewport, verb, detail: `${absoluteUrl(value)} -> ${lastStatus}` })
      } else if (verb === 'login') {
        const response = await page.goto(absoluteUrl('/login'), { waitUntil: 'domcontentloaded' })
        lastStatus = response ? response.status() : null
        await page.getByLabel('Email address', { exact: true }).fill(value.email)
        await page.getByLabel('Password', { exact: true }).fill(value.password)
        await page.locator('[data-test="login-button"]').click()
        try {
          await page.waitForURL((url) => !String(url).includes('/login'), { timeout: 15000 })
        } catch {
          const body = await page.textContent('body').catch(() => '')
          if (String(body).includes('Too Many Requests') || String(body).includes('429')) {
            await fail(verb, 'Fortify login throttle returned HTTP 429; wait one minute or relaunch')
          }
          await fail(verb, `login remained at ${page.url()}`)
        }
        record({ viewport, verb, detail: `${value.email} -> ${page.url()}` })
      } else if (verb === 'fill') {
        await page.locator(value.selector).fill(value.value)
        record({ viewport, verb, detail: value.selector })
      } else if (verb === 'fillLabel') {
        await page.getByLabel(value.label, { exact: value.exact !== false }).fill(value.value)
        record({ viewport, verb, detail: value.label })
      } else if (verb === 'click') {
        await page.locator(value).click()
        record({ viewport, verb, detail: value })
      } else if (verb === 'clickRole') {
        await page.getByRole(value.role, { name: value.name, exact: value.exact !== false }).click()
        record({ viewport, verb, detail: `${value.role} ${JSON.stringify(value.name)}` })
      } else if (verb === 'acceptDialog') {
        acceptNextDialog = Boolean(value)
        record({ viewport, verb, detail: acceptNextDialog ? 'next dialog will be accepted' : 'next dialog will be dismissed' })
      } else if (verb === 'expect') {
        await page.locator(value.selector).waitFor({ state: value.state || 'visible', timeout: value.timeout || 10000 })
        record({ viewport, verb, detail: `${value.selector} ${value.state || 'visible'}` })
      } else if (verb === 'expectRole') {
        await page.getByRole(value.role, { name: value.name, exact: value.exact !== false }).waitFor({ state: value.state || 'visible', timeout: value.timeout || 10000 })
        record({ viewport, verb, detail: `${value.role} ${JSON.stringify(value.name)}` })
      } else if (verb === 'expectMissing') {
        const count = await page.locator(value).count()
        if (count !== 0) await fail(verb, `${value} is present ${count} time(s)`)
        record({ viewport, verb, detail: `${value} absent` })
      } else if (verb === 'expectText') {
        const selector = value.selector || 'body'
        await page.locator(selector).filter({ hasText: value.contains }).first().waitFor({ state: 'visible', timeout: value.timeout || 10000 })
        record({ viewport, verb, detail: `${value.selector || 'body'} contains ${JSON.stringify(value.contains)}` })
      } else if (verb === 'expectUrl') {
        try {
          await page.waitForURL((url) => String(url).includes(value.contains), { timeout: value.timeout || 10000 })
        } catch {
          await fail(verb, `url is ${page.url()}, expected ${value.contains}`)
        }
        record({ viewport, verb, detail: page.url() })
      } else if (verb === 'expectStatus') {
        if (lastStatus !== value) await fail(verb, `last navigation was ${lastStatus}, expected ${value}`)
        record({ viewport, verb, detail: String(lastStatus) })
      } else if (verb === 'expectValue') {
        const actual = await page.locator(value.selector).inputValue()
        if (value.equals !== undefined && actual !== value.equals) await fail(verb, `${value.selector} value ${JSON.stringify(actual)} !== ${JSON.stringify(value.equals)}`)
        if (value.contains !== undefined && !actual.includes(value.contains)) await fail(verb, `${value.selector} value does not contain ${JSON.stringify(value.contains)}`)
        record({ viewport, verb, detail: `${value.selector} value matched` })
      } else if (verb === 'expectAttribute') {
        const actual = await page.locator(value.selector).first().getAttribute(value.name)
        if (value.equals !== undefined && actual !== value.equals) await fail(verb, `${value.selector}[${value.name}] ${JSON.stringify(actual)} !== ${JSON.stringify(value.equals)}`)
        if (value.contains !== undefined && !String(actual).includes(value.contains)) await fail(verb, `${value.selector}[${value.name}] does not contain ${JSON.stringify(value.contains)}`)
        if (value.missing === true && actual !== null) await fail(verb, `${value.selector}[${value.name}] is present`)
        record({ viewport, verb, detail: `${value.selector}[${value.name}] matched` })
      } else if (verb === 'expectFrameText') {
        const frame = page.frameLocator(value.selector || 'iframe[title="Sandboxed message body"]')
        const text = await frame.locator(value.innerSelector || 'body').textContent({ timeout: value.timeout || 10000 })
        if (!String(text).includes(value.contains)) await fail(verb, `frame does not contain ${JSON.stringify(value.contains)}`)
        record({ viewport, verb, detail: `frame contains ${JSON.stringify(value.contains)}` })
      } else if (verb === 'measure') {
        const box = await page.locator(value.selector).first().boundingBox()
        if (!box) await fail(verb, `${value.selector} has no rendered box`)
        record({ viewport, verb, detail: `${value.name || value.selector} ${JSON.stringify(box)}`, box })
      } else if (verb === 'overflow') {
        const overflows = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth)
        if (overflows && value !== 'allow') await fail(verb, 'page scrolls horizontally')
        record({ viewport, verb, detail: overflows ? 'overflows (allowed)' : 'no horizontal overflow' })
      } else if (verb === 'shot') {
        const file = await screenshot(page, viewport, value)
        record({ viewport, verb, detail: file, screenshot: file })
      } else if (verb === 'wait') {
        await page.waitForTimeout(value)
        record({ viewport, verb, detail: `${value}ms` })
      } else {
        await fail(verb, 'unknown verb')
      }
    } catch (error) {
      if (String(error.message).includes(' failed: ')) throw error
      await fail(verb, String(error.message).split('\n')[0])
    }
  }

  if (responseErrors.length > 0) record({ viewport, verb: 'httpErrors', detail: `${responseErrors.length}`, errors: responseErrors })
  if (consoleErrors.length > 0) await fail('consoleErrors', JSON.stringify(consoleErrors.slice(0, 20)))
  record({ viewport, verb: 'consoleErrors', detail: 'none' })
  await context.close()
}

;(async () => {
  const browser = await chromium.launch({ headless: true, args: ['--mute-audio'] })
  try {
    for (const viewport of viewports) await runViewport(browser, viewport)
  } finally {
    await browser.close()
  }
  console.log(`\nevidence: ${args.out}\ntranscript: ${transcript}`)
})().catch((error) => {
  console.error(`\nDRIVE FAILED: ${error.message}`)
  process.exit(1)
})

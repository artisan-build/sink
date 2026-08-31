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
const processSecrets = new Set()

function interpolate(value, viewport, variables = Object.create(null)) {
	if (typeof value === 'string') {
		return value.replace(/\{\{([^}]+)}}/g, (match, name) => {
			if (name === 'viewport') return viewport
			return Object.hasOwn(variables, name) ? variables[name] : match
		})
	}
	if (Array.isArray(value)) return value.map((item) => interpolate(item, viewport, variables))
	if (value && typeof value === 'object') {
		return Object.fromEntries(Object.entries(value).map(([key, item]) => [key, interpolate(item, viewport, variables)]))
	}
	return value
}

function configuredSecrets(viewport) {
	const secrets = new Set()
	for (const rawStep of steps) {
		const step = interpolate(rawStep, viewport)
		const [verb, value] = Object.entries(step)[0] || []
		if (verb === 'login' && typeof value?.password === 'string') secrets.add(value.password)
		if (verb === 'fillLabel' && /password/i.test(value?.label || '') && typeof value?.value === 'string') secrets.add(value.value)
		if (verb === 'fill' && /password/i.test(value?.selector || '') && typeof value?.value === 'string') secrets.add(value.value)
	}
	secrets.forEach((secret) => processSecrets.add(secret))
	return secrets
}

function redactText(value, secrets) {
	let redacted = String(value)
	for (const secret of secrets) {
		if (secret) redacted = redacted.replaceAll(secret, '[REDACTED]')
	}
	return redacted
		.replace(/(\/register\/)[^/?#\s"'<>]+/gi, '$1[REDACTED]')
		.replace(/\b[A-Za-z0-9]{40}\b/g, '[REDACTED]')
}

function redact(value, secrets) {
	if (typeof value === 'string') return redactText(value, secrets)
	if (Array.isArray(value)) return value.map((item) => redact(item, secrets))
	if (value && typeof value === 'object') {
		return Object.fromEntries(Object.entries(value).map(([key, item]) => [key, redact(item, secrets)]))
	}
	return value
}

function record(entry, secrets) {
	const safeEntry = redact(entry, secrets)
	fs.appendFileSync(transcript, JSON.stringify({ at: new Date().toISOString(), ...safeEntry }) + '\n')
	console.log(`${safeEntry.ok === false ? 'FAIL' : 'ok  '} [${safeEntry.viewport}] ${safeEntry.verb} ${safeEntry.detail || ''}`)
}

const absoluteUrl = (value) => (/^https?:/.test(value) ? value : args.base + value)

async function screenshot(page, viewport, name, secrets) {
	const safeName = redactText(name, secrets).replace(/[^A-Za-z0-9_.-]+/g, '_')
	const file = path.join(args.out, `${label}--${viewport}--${safeName}.png`)
	const marker = 'data-sink-verify-redacted'
	await page.evaluate(({ marker, secrets: secretValues }) => {
		const invitationUrl = /\/register\/[^/?#\s"'<>]+/i
		const invitationToken = /\b[A-Za-z0-9]{40}\b/
		const sensitive = (candidate) => {
			const text = String(candidate || '')
			return invitationUrl.test(text) || invitationToken.test(text) || secretValues.some((secret) => secret && text.includes(secret))
		}
		const mark = (element) => element?.setAttribute(marker, 'true')

		document.querySelectorAll('input[type="password"], input[autocomplete*="password"], input[name*="password" i], input[id*="password" i]').forEach(mark)
		document.querySelectorAll('input, textarea, a').forEach((element) => {
			if (sensitive(element.value) || sensitive(element.getAttribute('href'))) mark(element)
		})

		const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT)
		while (walker.nextNode()) {
			if (sensitive(walker.currentNode.nodeValue)) mark(walker.currentNode.parentElement)
		}
	}, { marker, secrets: [...secrets] })

	try {
		await page.screenshot({ path: file, fullPage: true, mask: [page.locator(`[${marker}]`)], maskColor: '#000000' })
	} finally {
		await page.locator(`[${marker}]`).evaluateAll((elements, attribute) => elements.forEach((element) => element.removeAttribute(attribute)), marker).catch(() => {})
	}
	return file
}

async function runViewport(browser, viewport) {
	if (!/^\d+x\d+$/.test(viewport)) throw new Error(`invalid viewport ${viewport}`)
	const [width, height] = viewport.split('x').map(Number)
	let context = await browser.newContext({ viewport: { width, height } })
	let page = await context.newPage()
	const consoleErrors = []
	const responseErrors = []
	const variables = Object.create(null)
	const secrets = configuredSecrets(viewport)
	let lastStatus = null
	let acceptNextDialog = false

	const observe = (observedPage) => {
		observedPage.on('console', (message) => {
			if (message.type() === 'error') consoleErrors.push(message.text())
		})
		observedPage.on('response', (response) => {
			if (response.status() >= 400) responseErrors.push({ status: response.status(), url: response.url() })
		})
		observedPage.on('dialog', async (dialog) => {
			if (acceptNextDialog) {
				acceptNextDialog = false
				await dialog.accept()
			} else {
				await dialog.dismiss()
			}
		})
	}
	observe(page)

	const fail = async (verb, detail) => {
		const file = await screenshot(page, viewport, `FAILED-${verb}`, secrets)
		record({ viewport, verb, detail, ok: false, screenshot: file, url: page.url() }, secrets)
		await context.close()
		throw new Error(`${verb} failed: ${redactText(detail, secrets)}`)
	}

	for (const rawStep of steps) {
		const step = interpolate(rawStep, viewport, variables)
    const keys = Object.keys(step)
    if (keys.length !== 1) await fail('step', 'each step must contain exactly one verb')
    const verb = keys[0]
    const value = step[verb]

    try {
      if (verb === 'goto') {
        const response = await page.goto(absoluteUrl(value), { waitUntil: 'domcontentloaded' })
        lastStatus = response ? response.status() : null
			record({ viewport, verb, detail: `${absoluteUrl(value)} -> ${lastStatus}` }, secrets)
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
			record({ viewport, verb, detail: `${value.email} -> ${page.url()}` }, secrets)
		} else if (verb === 'fill') {
			await page.locator(value.selector).fill(value.value)
			record({ viewport, verb, detail: value.selector }, secrets)
		} else if (verb === 'fillLabel') {
			await page.getByLabel(value.label, { exact: value.exact !== false }).fill(value.value)
			record({ viewport, verb, detail: value.label }, secrets)
		} else if (verb === 'click') {
			await page.locator(value).click()
			record({ viewport, verb, detail: value }, secrets)
		} else if (verb === 'clickRole') {
			await page.getByRole(value.role, { name: value.name, exact: value.exact !== false }).click()
			record({ viewport, verb, detail: `${value.role} ${JSON.stringify(value.name)}` }, secrets)
		} else if (verb === 'clickNewPage') {
			const nextPagePromise = context.waitForEvent('page')
			if (value.selector) await page.locator(value.selector).click()
			else await page.getByRole(value.role, { name: value.name, exact: value.exact !== false }).click()
			page = await nextPagePromise
			observe(page)
			await page.waitForLoadState('domcontentloaded')
			lastStatus = null
			record({ viewport, verb, detail: page.url() }, secrets)
		} else if (verb === 'captureValue') {
			const captured = value.attribute
				? await page.locator(value.selector).first().getAttribute(value.attribute)
				: await page.locator(value.selector).first().inputValue()
			if (!captured) await fail(verb, `${value.selector} produced no value`)
			variables[value.name] = captured
			secrets.add(captured)
			processSecrets.add(captured)
			record({ viewport, verb, detail: `${value.name} captured as [REDACTED]` }, secrets)
		} else if (verb === 'newContext') {
			await context.close()
			context = await browser.newContext({ viewport: { width, height } })
			page = await context.newPage()
			observe(page)
			lastStatus = null
			record({ viewport, verb, detail: 'fresh isolated browser context' }, secrets)
		} else if (verb === 'acceptDialog') {
			acceptNextDialog = Boolean(value)
			record({ viewport, verb, detail: acceptNextDialog ? 'next dialog will be accepted' : 'next dialog will be dismissed' }, secrets)
      } else if (verb === 'expect') {
        await page.locator(value.selector).waitFor({ state: value.state || 'visible', timeout: value.timeout || 10000 })
			record({ viewport, verb, detail: `${value.selector} ${value.state || 'visible'}` }, secrets)
      } else if (verb === 'expectRole') {
        await page.getByRole(value.role, { name: value.name, exact: value.exact !== false }).waitFor({ state: value.state || 'visible', timeout: value.timeout || 10000 })
			record({ viewport, verb, detail: `${value.role} ${JSON.stringify(value.name)}` }, secrets)
      } else if (verb === 'expectMissing') {
        const count = await page.locator(value).count()
        if (count !== 0) await fail(verb, `${value} is present ${count} time(s)`)
			record({ viewport, verb, detail: `${value} absent` }, secrets)
      } else if (verb === 'expectText') {
        const selector = value.selector || 'body'
        await page.locator(selector).filter({ hasText: value.contains }).first().waitFor({ state: 'visible', timeout: value.timeout || 10000 })
			record({ viewport, verb, detail: `${value.selector || 'body'} contains ${JSON.stringify(value.contains)}` }, secrets)
      } else if (verb === 'expectUrl') {
        try {
          await page.waitForURL((url) => String(url).includes(value.contains), { timeout: value.timeout || 10000 })
        } catch {
          await fail(verb, `url is ${page.url()}, expected ${value.contains}`)
        }
			record({ viewport, verb, detail: page.url() }, secrets)
      } else if (verb === 'expectStatus') {
        if (lastStatus !== value) await fail(verb, `last navigation was ${lastStatus}, expected ${value}`)
			record({ viewport, verb, detail: String(lastStatus) }, secrets)
		} else if (verb === 'expectValue') {
			const locator = value.label
				? page.getByLabel(value.label, { exact: value.exact !== false })
				: page.locator(value.selector)
			const target = value.label || value.selector
			const actual = await locator.inputValue()
			if (value.equals !== undefined && actual !== value.equals) await fail(verb, `${target} value ${JSON.stringify(actual)} !== ${JSON.stringify(value.equals)}`)
			if (value.contains !== undefined && !actual.includes(value.contains)) await fail(verb, `${target} value does not contain ${JSON.stringify(value.contains)}`)
			record({ viewport, verb, detail: `${target} value matched` }, secrets)
		} else if (verb === 'expectAttribute') {
        const actual = await page.locator(value.selector).first().getAttribute(value.name)
        if (value.equals !== undefined && actual !== value.equals) await fail(verb, `${value.selector}[${value.name}] ${JSON.stringify(actual)} !== ${JSON.stringify(value.equals)}`)
        if (value.contains !== undefined && !String(actual).includes(value.contains)) await fail(verb, `${value.selector}[${value.name}] does not contain ${JSON.stringify(value.contains)}`)
        if (value.missing === true && actual !== null) await fail(verb, `${value.selector}[${value.name}] is present`)
			record({ viewport, verb, detail: `${value.selector}[${value.name}] matched` }, secrets)
		} else if (verb === 'expectChecked') {
			const checked = await page.locator(value.selector).first().isChecked()
			if (checked !== (value.checked !== false)) await fail(verb, `${value.selector} checked state was ${checked}`)
			record({ viewport, verb, detail: `${value.selector} checked state matched` }, secrets)
      } else if (verb === 'expectFrameText') {
        const frame = page.frameLocator(value.selector || 'iframe[title="Sandboxed message body"]')
        const text = await frame.locator(value.innerSelector || 'body').textContent({ timeout: value.timeout || 10000 })
        if (!String(text).includes(value.contains)) await fail(verb, `frame does not contain ${JSON.stringify(value.contains)}`)
			record({ viewport, verb, detail: `frame contains ${JSON.stringify(value.contains)}` }, secrets)
      } else if (verb === 'measure') {
        const box = await page.locator(value.selector).first().boundingBox()
        if (!box) await fail(verb, `${value.selector} has no rendered box`)
			record({ viewport, verb, detail: `${value.name || value.selector} ${JSON.stringify(box)}`, box }, secrets)
      } else if (verb === 'overflow') {
        const overflows = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth)
        if (overflows && value !== 'allow') await fail(verb, 'page scrolls horizontally')
			record({ viewport, verb, detail: overflows ? 'overflows (allowed)' : 'no horizontal overflow' }, secrets)
		} else if (verb === 'shot') {
			const file = await screenshot(page, viewport, value, secrets)
			record({ viewport, verb, detail: file, screenshot: file }, secrets)
		} else if (verb === 'wait') {
			await page.waitForTimeout(value)
			record({ viewport, verb, detail: `${value}ms` }, secrets)
      } else {
        await fail(verb, 'unknown verb')
      }
    } catch (error) {
      if (String(error.message).includes(' failed: ')) throw error
      await fail(verb, String(error.message).split('\n')[0])
    }
  }

	if (responseErrors.length > 0) record({ viewport, verb: 'httpErrors', detail: `${responseErrors.length}`, errors: responseErrors }, secrets)
	if (consoleErrors.length > 0) await fail('consoleErrors', JSON.stringify(consoleErrors.slice(0, 20)))
	record({ viewport, verb: 'consoleErrors', detail: 'none' }, secrets)
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
	console.error(`\nDRIVE FAILED: ${redactText(error.message, processSecrets)}`)
	process.exit(1)
})

(function(window) {
  'use strict';

  function createSongsAudioPitchModule() {
    const AudioContextCtor = window.AudioContext || window.webkitAudioContext || null;
    const GRAIN_SECONDS = 0.18;
    const STEP_SECONDS = 0.055;
    const SCHEDULE_AHEAD_SECONDS = 0.42;
    const TIMER_MS = 45;

    const state = {
      ctx: null,
      output: null,
      buffer: null,
      url: '',
      semitones: 0,
      position: 0,
      startContextTime: 0,
      nextGrainTime: 0,
      nextGrainOffset: 0,
      playing: false,
      timer: 0,
      sources: [],
      loadToken: 0,
      onEnded: null,
      onTick: null
    };

    function clamp(value, min, max) {
      const number = Number(value);
      if (!Number.isFinite(number)) return min;
      return Math.min(max, Math.max(min, number));
    }

    function normalizeSemitones(value) {
      return Math.round(clamp(value, -12, 12));
    }

    function ratioFromSemitones(value) {
      return Math.pow(2, normalizeSemitones(value) / 12);
    }

    function isSupported() {
      return !!AudioContextCtor;
    }

    async function ensureContext() {
      if (!AudioContextCtor) throw new Error('WebAudio ist nicht verfuegbar.');
      if (!state.ctx) {
        state.ctx = new AudioContextCtor();
        state.output = state.ctx.createGain();
        state.output.gain.value = 1;
        state.output.connect(state.ctx.destination);
      }
      if (state.ctx.state === 'suspended') {
        await state.ctx.resume();
      }
      return state.ctx;
    }

    function decodeAudioData(ctx, arrayBuffer) {
      return new Promise((resolve, reject) => {
        let settled = false;
        const done = (buffer) => {
          if (settled) return;
          settled = true;
          resolve(buffer);
        };
        const fail = (error) => {
          if (settled) return;
          settled = true;
          reject(error);
        };
        try {
          const decoded = ctx.decodeAudioData(arrayBuffer.slice(0), done, fail);
          if (decoded && typeof decoded.then === 'function') decoded.then(done, fail);
        } catch (error) {
          fail(error);
        }
      });
    }

    function credentialsForUrl(url) {
      try {
        return new URL(url, window.location.href).origin === window.location.origin ? 'same-origin' : 'omit';
      } catch (error) {
        return 'same-origin';
      }
    }

    async function loadBuffer(url, token) {
      const wantedUrl = String(url || '').trim();
      if (!wantedUrl) throw new Error('Keine Audio-URL.');
      if (state.buffer && state.url === wantedUrl) return state.buffer;

      const ctx = await ensureContext();
      if (token !== state.loadToken) return null;

      const response = await fetch(wantedUrl, {
        mode: 'cors',
        credentials: credentialsForUrl(wantedUrl),
        cache: 'force-cache'
      });
      if (!response.ok) throw new Error('Audio konnte nicht geladen werden.');
      const arrayBuffer = await response.arrayBuffer();
      if (token !== state.loadToken) return null;
      const buffer = await decodeAudioData(ctx, arrayBuffer);
      if (token !== state.loadToken) return null;
      state.buffer = buffer;
      state.url = wantedUrl;
      state.position = 0;
      return buffer;
    }

    function removeSource(source) {
      state.sources = state.sources.filter(entry => entry !== source);
    }

    function stopScheduledSources() {
      if (state.timer) {
        clearInterval(state.timer);
        state.timer = 0;
      }
      state.sources.forEach((source) => {
        try { source.onended = null; source.stop(0); } catch (error) {}
        try { source.disconnect(); } catch (error) {}
      });
      state.sources = [];
    }

    function getDuration() {
      return state.buffer && Number.isFinite(state.buffer.duration) ? state.buffer.duration : 0;
    }

    function getCurrentTime() {
      const duration = getDuration();
      if (!duration) return 0;
      const current = state.playing && state.ctx
        ? state.ctx.currentTime - state.startContextTime
        : state.position;
      return clamp(current, 0, duration);
    }

    function tick() {
      if (typeof state.onTick === 'function') {
        try { state.onTick(); } catch (error) {}
      }
    }

    function scheduleGrain(ctx, when, offset, grainSeconds, ratio) {
      if (!state.buffer || !state.output) return;
      const duration = getDuration();
      if (!duration || offset >= duration) return;
      const safeOffset = clamp(offset, 0, Math.max(0, duration - 0.01));
      const sourceDuration = Math.min(Math.max(0.01, grainSeconds * ratio), Math.max(0.01, duration - safeOffset));
      const source = ctx.createBufferSource();
      const gain = ctx.createGain();
      source.buffer = state.buffer;
      source.playbackRate.value = ratio;

      const attack = Math.min(0.025, grainSeconds / 4);
      const release = Math.min(0.04, grainSeconds / 3);
      const releaseStart = Math.max(when + attack, when + grainSeconds - release);
      gain.gain.setValueAtTime(0, when);
      gain.gain.linearRampToValueAtTime(1, when + attack);
      gain.gain.setValueAtTime(1, releaseStart);
      gain.gain.linearRampToValueAtTime(0, when + grainSeconds);

      source.connect(gain);
      gain.connect(state.output);
      source.onended = () => {
        removeSource(source);
        try { gain.disconnect(); } catch (error) {}
      };
      state.sources.push(source);
      try {
        source.start(Math.max(ctx.currentTime, when), safeOffset, sourceDuration);
        source.stop(when + grainSeconds + 0.04);
      } catch (error) {
        removeSource(source);
        try { source.disconnect(); } catch (ignore) {}
        try { gain.disconnect(); } catch (ignore) {}
      }
    }

    function finishPlayback() {
      const ended = state.onEnded;
      state.position = getDuration();
      state.playing = false;
      stopScheduledSources();
      tick();
      if (typeof ended === 'function') setTimeout(ended, 0);
    }

    function schedule() {
      if (!state.playing || !state.ctx || !state.buffer) return;
      const duration = getDuration();
      if (duration > 0 && getCurrentTime() >= duration - 0.015) {
        finishPlayback();
        return;
      }

      const ratio = ratioFromSemitones(state.semitones);
      const ctx = state.ctx;
      const horizon = ctx.currentTime + SCHEDULE_AHEAD_SECONDS;
      while (state.nextGrainTime < horizon && state.nextGrainOffset < duration) {
        scheduleGrain(ctx, state.nextGrainTime, state.nextGrainOffset, GRAIN_SECONDS, ratio);
        state.nextGrainTime += STEP_SECONDS;
        state.nextGrainOffset += STEP_SECONDS;
      }
      tick();
    }

    function startScheduled(position) {
      if (!state.ctx || !state.buffer) return false;
      stopScheduledSources();
      const duration = getDuration();
      state.position = clamp(position, 0, duration);
      state.startContextTime = state.ctx.currentTime - state.position;
      state.nextGrainTime = state.ctx.currentTime + 0.025;
      state.nextGrainOffset = state.position;
      state.playing = true;
      schedule();
      state.timer = setInterval(schedule, TIMER_MS);
      tick();
      return true;
    }

    async function play(url, options) {
      const opts = options || {};
      const token = ++state.loadToken;
      state.semitones = normalizeSemitones(opts.semitones);
      state.onEnded = typeof opts.onEnded === 'function' ? opts.onEnded : null;
      state.onTick = typeof opts.onTick === 'function' ? opts.onTick : null;
      const requestedPosition = Number.isFinite(Number(opts.position)) ? Number(opts.position) : state.position;

      const ctx = await ensureContext();
      if (token !== state.loadToken) return false;
      if (ctx.state === 'suspended') await ctx.resume();
      const buffer = await loadBuffer(url, token);
      if (!buffer || token !== state.loadToken) return false;
      return startScheduled(requestedPosition);
    }

    function pause() {
      if (!state.playing) return;
      state.position = getCurrentTime();
      state.playing = false;
      stopScheduledSources();
      tick();
    }

    function stop(options) {
      const opts = options || {};
      state.loadToken += 1;
      if (state.playing) state.position = getCurrentTime();
      state.playing = false;
      stopScheduledSources();
      if (opts.resetPosition !== false) state.position = 0;
      if (opts.clearBuffer) {
        state.buffer = null;
        state.url = '';
      }
      tick();
    }

    function seek(seconds) {
      const position = clamp(seconds, 0, getDuration());
      const wasPlaying = state.playing;
      state.position = position;
      if (wasPlaying) startScheduled(position);
      tick();
    }

    function setSemitones(value) {
      state.semitones = normalizeSemitones(value);
      if (state.playing) startScheduled(getCurrentTime());
      tick();
    }

    return {
      isSupported,
      isPlaying: () => !!state.playing,
      isLoaded: (url) => !!state.buffer && (!url || state.url === String(url || '').trim()),
      currentUrl: () => state.url,
      currentTime: getCurrentTime,
      duration: getDuration,
      getSemitones: () => state.semitones,
      setSemitones,
      play,
      pause,
      stop,
      seek
    };
  }

  window.SongsAudioPitchModule = createSongsAudioPitchModule();
})(window);

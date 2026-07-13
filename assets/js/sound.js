/**
 * Lightweight sound effects synthesized with the Web Audio API, so the game
 * needs no external audio files.
 */
const Sound = (() => {
  let ctx = null;

  function ensureCtx() {
    if (!ctx) {
      const AudioCtx = window.AudioContext || window.webkitAudioContext;
      ctx = new AudioCtx();
    }
    if (ctx.state === 'suspended') ctx.resume();
    return ctx;
  }

  function tone(freq, start, duration, type = 'sine', gainPeak = 0.18) {
    const c = ensureCtx();
    const osc = c.createOscillator();
    const gain = c.createGain();
    osc.type = type;
    osc.frequency.value = freq;
    gain.gain.setValueAtTime(0, c.currentTime + start);
    gain.gain.linearRampToValueAtTime(gainPeak, c.currentTime + start + 0.02);
    gain.gain.exponentialRampToValueAtTime(0.0001, c.currentTime + start + duration);
    osc.connect(gain).connect(c.destination);
    osc.start(c.currentTime + start);
    osc.stop(c.currentTime + start + duration + 0.05);
  }

  return {
    unlock() { ensureCtx(); },

    playCard() {
      tone(520, 0, 0.09, 'triangle', 0.16);
      tone(700, 0.05, 0.08, 'triangle', 0.12);
    },

    drawCard() {
      tone(300, 0, 0.07, 'square', 0.08);
      tone(260, 0.05, 0.09, 'square', 0.07);
    },

    uno() {
      tone(660, 0, 0.12, 'sawtooth', 0.18);
      tone(880, 0.1, 0.12, 'sawtooth', 0.18);
      tone(1046, 0.2, 0.22, 'sawtooth', 0.2);
    },

    turnAlert() {
      tone(440, 0, 0.1, 'sine', 0.12);
    },

    timerWarn() {
      tone(880, 0, 0.08, 'square', 0.1);
    },

    roundWin() {
      [523, 659, 784, 1046].forEach((f, i) => tone(f, i * 0.11, 0.18, 'triangle', 0.16));
    },

    gameOver() {
      [392, 523, 659, 784, 987].forEach((f, i) => tone(f, i * 0.14, 0.28, 'sine', 0.18));
    },

    kicked() {
      tone(300, 0, 0.15, 'sawtooth', 0.15);
      tone(180, 0.12, 0.25, 'sawtooth', 0.15);
    },

    chatPop() {
      tone(700, 0, 0.05, 'sine', 0.08);
    }
  };
})();
